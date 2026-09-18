<?php

namespace App\Services\Dav;

use App\Dav\Server;
use App\Models\Vmail\Mailbox;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Sabre\DAV\Sharing\Plugin as Sharing;
use Sabre\DAV\Xml\Element\Sharee;

/** Общий доступ к календарю и занятость участников. */
class DavSharing
{
    public function __construct(
        private readonly DavAccess $a,
        private readonly Calendars $cal,
    ) {
    }


    // ── Совместный доступ к календарю ───────────────────────────────────

    /** @return array<int,array{mail:string,name:string,level:string}> */
    public function shares(string $user, string $calUri): array
    {
        $c = $this->calendar($user, $calUri);
        $out = [];
        foreach ($this->a->cals->getInvites([$c['id'], $c['instance']]) as $sharee) {
            if (in_array($sharee->access, [Sharing::ACCESS_READ, Sharing::ACCESS_READWRITE], true)) {
                $mail = strtolower(preg_replace('/^mailto:/i', '', $sharee->href));
                $out[] = ['mail' => $mail, 'name' => $this->a->displayName($mail), 'level' => $sharee->access === Sharing::ACCESS_READWRITE ? 'write' : 'read'];
            }
        }

        return $out;
    }


    public function share(string $user, string $calUri, string $with, string $level): array
    {
        $c = $this->calendar($user, $calUri);
        if ($c['kind'] !== 'personal' && $c['kind'] !== 'own') {
            throw new DavException('Делиться можно только своим календарём', 403);
        }
        $with = strtolower(trim($with));
        if (! Mailbox::query()->where('username', $with)->exists()) {
            throw new DavException('Такого сотрудника нет', 422);
        }
        if ($with === strtolower($user)) {
            throw new DavException('Это ваш собственный календарь', 422);
        }
        $this->a->ensureUser($with);
        $this->a->cals->updateInvites([$c['id'], $c['instance']], [new Sharee([
            'href' => 'mailto:' . $with,
            'principal' => Server::principal($with),
            'access' => $level === 'write' ? Sharing::ACCESS_READWRITE : Sharing::ACCESS_READ,
            'inviteStatus' => Sharing::INVITE_ACCEPTED,
            'properties' => ['{DAV:}displayname' => $this->a->displayName($with)],
        ])]);

        return $this->shares($user, $calUri);
    }


    public function unshare(string $user, string $calUri, string $with): array
    {
        $c = $this->calendar($user, $calUri);
        $this->a->cals->updateInvites([$c['id'], $c['instance']], [new Sharee([
            'href' => 'mailto:' . strtolower(trim($with)),
            'principal' => Server::principal($with),
            'access' => Sharing::ACCESS_NOACCESS,
        ])]);

        return $this->shares($user, $calUri);
    }


    /**
     * Занятость сотрудников: только интервалы, без названий (политика компании — занятость видна всем).
     *
     * @param  string[]  $users
     * @return array<string,array<int,array{start:string,end:string}>|null> null — адрес не наш, занятость неизвестна
     */
    public function freeBusy(array $users, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $out = [];
        foreach ($users as $u) {
            $u = strtolower(trim($u));
            // У чужого адреса календаря здесь нет и быть не может. Пустой список значил бы
            // «весь день свободен», и внешний участник выглядел готовым к встрече в любое
            // время. Отвечаем null — «неизвестно», интерфейс так и подписывает эту строку.
            if (! Mailbox::query()->where('username', $u)->exists()) {
                $out[$u] = null;
                continue;
            }
            $out[$u] = [];
            $instances = DB::table('dav_calendarinstances')->where('principaluri', Server::principal($u))->where('access', 1)->get(['id', 'calendarid']);
            foreach ($instances as $inst) {
                foreach ($this->cal->objectsInRange([(int) $inst->calendarid, (int) $inst->id], $from, $to) as $row) {
                    foreach (Events::occurrences((string) $row['calendardata'], $from, $to) as $item) {
                        if ($item['transparent'] || $item['status'] === 'CANCELLED') {
                            continue;
                        }
                        $declined = false;
                        foreach ($item['attendees'] as $a) {
                            if ($a['mail'] === $u && $a['status'] === 'DECLINED') {
                                $declined = true;
                            }
                        }
                        if (! $declined) {
                            $out[$u][] = ['start' => $item['start'], 'end' => $item['end'], 'allDay' => $item['allDay']];
                        }
                    }
                }
            }
        }

        return $out;
    }
}
