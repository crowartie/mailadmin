<?php

namespace App\Services\Dav;

use App\Dav\CalBackend;
use App\Dav\CardBackend;
use App\Dav\Server;
use App\Models\Vmail\Mailbox;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Sharing\Plugin as Sharing;
use Sabre\DAV\Xml\Element\Sharee;

/**
 * Книги и календари для интерфейса. Чтение — напрямую из таблиц dav_* через бэкенды sabre,
 * запись — через встроенный DAV-сервер (Server::call), чтобы приглашения, синхронизация
 * и ETag работали так же, как для телефона.
 */
class DavStore
{
    public const PERSONAL = 'personal';

    private CardBackend $cards;

    private CalBackend $cals;

    public function __construct()
    {
        $pdo = DB::connection()->getPdo();
        $this->cards = new CardBackend($pdo);
        $this->cals = new CalBackend($pdo);
    }

    // ── Пользователи ─────────────────────────────────────────────────────

    /** Глобальный администратор почты (domain_admins ALL) правит общие книги и календарь компании. */
    public function isAdmin(string $user): bool
    {
        return DB::connection('vmail')->table('domain_admins')->where('username', strtolower($user))->where('active', 1)
            ->where(fn ($q) => $q->where('domain', 'ALL')->orWhere('domain', explode('@', $user)[1] ?? ''))->exists();
    }

    public function displayName(string $user): string
    {
        $name = Mailbox::query()->where('username', strtolower($user))->value('name');

        return trim((string) $name) ?: explode('@', $user)[0];
    }

    /** @var array<string,bool> */
    private static array $ensured = [];

    private function ensureOnce(string $user): void
    {
        $user = strtolower($user);
        if (! isset(self::$ensured[$user])) {
            self::$ensured[$user] = true;
            $this->ensureUser($user);
        }
    }

    /** Principal, личная книга и личный календарь — создаются при первом входе. */
    public function ensureUser(string $user): void
    {
        $user = strtolower($user);
        $principal = Server::principal($user);
        $name = $this->displayName($user);

        $exists = DB::table('dav_principals')->where('uri', $principal)->exists();
        if (! $exists) {
            DB::table('dav_principals')->insert(['uri' => $principal, 'email' => $user, 'displayname' => $name]);
        } else {
            DB::table('dav_principals')->where('uri', $principal)->update(['email' => $user, 'displayname' => $name]);
        }

        if (! DB::table('dav_addressbooks')->where('principaluri', $principal)->where('uri', self::PERSONAL)->exists()) {
            $this->cards->createAddressBook($principal, self::PERSONAL, ['{DAV:}displayname' => 'Мои контакты']);
        }
        if (! DB::table('dav_calendarinstances')->where('principaluri', $principal)->where('uri', self::PERSONAL)->exists()) {
            $this->cals->createCalendar($principal, self::PERSONAL, [
                '{DAV:}displayname' => 'Мой календарь',
                '{http://apple.com/ns/ical/}calendar-color' => '#2F6FEB',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT', 'VTODO']),
            ]);
        }
    }

    // ── DAV изнутри ──────────────────────────────────────────────────────

    /** @return array{0:int,1:string,2:array} */
    private function dav(string $user, string $method, string $path, string $body = '', array $headers = []): array
    {
        $result = Server::call($user, $method, $path, $body, $headers, $this->isAdmin($user));
        if ($result[0] >= 400) {
            throw new DavException(Server::errorMessage($result[1], $result[0]), $result[0]);
        }

        return $result;
    }

    // ── Адресные книги ───────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function books(string $user): array
    {
        $this->ensureOnce($user);
        $out = [];
        foreach ($this->cards->getAddressBooksForUser(Server::principal($user)) as $b) {
            $count = (int) DB::table('dav_cards')->where('addressbookid', $b['id'])->count();
            $kind = ! empty($b['shared']) ? $b['uri'] : ($b['uri'] === self::PERSONAL ? 'personal' : 'own');
            $out[] = [
                'id' => (int) $b['id'],
                'uri' => $b['uri'],
                'name' => $b['{DAV:}displayname'] ?: $b['uri'],
                'description' => $b['{urn:ietf:params:xml:ns:carddav}addressbook-description'] ?? '',
                'kind' => $kind,
                'readonly' => ! empty($b['shared']) && ! $this->isAdmin($user),
                'count' => $count,
            ];
        }
        usort($out, fn ($a, $b) => ['personal' => 0, 'own' => 1, 'employees' => 2, 'company' => 3][$a['kind']] <=> ['personal' => 0, 'own' => 1, 'employees' => 2, 'company' => 3][$b['kind']]);

        return $out;
    }

    private function book(string $user, string $uri): array
    {
        foreach ($this->books($user) as $b) {
            if ($b['uri'] === $uri) {
                return $b;
            }
        }
        throw new DavException('Адресная книга не найдена', 404);
    }

    /** @return array<int,array<string,mixed>> */
    public function cards(string $user, ?string $bookUri = null, string $q = ''): array
    {
        $books = $bookUri ? [$this->book($user, $bookUri)] : $this->books($user);
        $q = mb_strtolower(trim($q));
        $out = [];
        foreach ($books as $b) {
            $rows = DB::table('dav_cards')->where('addressbookid', $b['id'])->get(['uri', 'carddata', 'etag']);
            foreach ($rows as $row) {
                $c = Cards::parse((string) $row->carddata, $row->uri, $row->etag);
                $c['book'] = $b['uri'];
                $c['bookName'] = $b['name'];
                $c['readonly'] = $b['readonly'];
                if ($q !== '' && ! $this->matches($c, $q)) {
                    continue;
                }
                unset($c['photo']);
                $out[] = $c;
            }
        }
        usort($out, fn ($a, $b) => strcoll(mb_strtolower($a['fn']), mb_strtolower($b['fn'])));

        return $out;
    }

    private function matches(array $c, string $q): bool
    {
        $hay = mb_strtolower(implode(' ', array_merge(
            [$c['fn'], $c['org'], $c['title'], $c['note'], $c['nick'], $c['department']],
            array_column($c['emails'], 'value'),
            array_column($c['phones'], 'value'),
            $c['groups']
        )));
        foreach (preg_split('/\s+/', $q) as $word) {
            if ($word !== '' && ! str_contains($hay, $word)) {
                return false;
            }
        }

        return true;
    }

    public function card(string $user, string $bookUri, string $uri): array
    {
        $b = $this->book($user, $bookUri);
        $row = DB::table('dav_cards')->where('addressbookid', $b['id'])->where('uri', $uri)->first(['uri', 'carddata', 'etag']);
        if (! $row) {
            throw new DavException('Контакт не найден', 404);
        }
        $c = Cards::parse((string) $row->carddata, $row->uri, $row->etag);
        $c['book'] = $b['uri'];
        $c['bookName'] = $b['name'];
        $c['readonly'] = $b['readonly'];
        $c['raw'] = (string) $row->carddata;

        return $c;
    }

    /** Создать или обновить карточку. @return array карточка после сохранения */
    public function saveCard(string $user, string $bookUri, ?string $uri, array $data): array
    {
        $b = $this->book($user, $bookUri);
        $existing = null;
        if ($uri) {
            $existing = DB::table('dav_cards')->where('addressbookid', $b['id'])->where('uri', $uri)->value('carddata');
        }
        $vcf = Cards::build($data, $existing ? (string) $existing : null);
        $uri = $uri ?: Cards::uid() . '.vcf';
        $this->dav($user, 'PUT', "addressbooks/{$user}/{$bookUri}/{$uri}", $vcf, ['Content-Type' => 'text/vcard; charset=utf-8']);

        return $this->card($user, $bookUri, $uri);
    }

    public function deleteCard(string $user, string $bookUri, string $uri): void
    {
        $this->dav($user, 'DELETE', "addressbooks/{$user}/{$bookUri}/{$uri}");
    }

    /** Перенести карточку в другую книгу (копия + удаление). */
    public function moveCard(string $user, string $fromBook, string $uri, string $toBook): array
    {
        $c = $this->card($user, $fromBook, $uri);
        $this->dav($user, 'PUT', "addressbooks/{$user}/{$toBook}/{$uri}", $c['raw'], ['Content-Type' => 'text/vcard; charset=utf-8']);
        $this->deleteCard($user, $fromBook, $uri);

        return $this->card($user, $toBook, $uri);
    }

    /** Импорт .vcf (одна или много карточек). @return int сколько добавлено */
    public function importCards(string $user, string $bookUri, string $vcfText): int
    {
        $n = 0;
        foreach (Cards::split($vcfText) as $vcf) {
            $uri = Cards::uid() . '.vcf';
            // Чужая карточка может быть vCard 4.0 или без FN — прогоняем через свою сборку.
            $normalized = Cards::build(Cards::parse($vcf), $vcf);
            $this->dav($user, 'PUT', "addressbooks/{$user}/{$bookUri}/{$uri}", $normalized, ['Content-Type' => 'text/vcard; charset=utf-8']);
            $n++;
        }

        return $n;
    }

    public function exportCards(string $user, ?string $bookUri): string
    {
        $books = $bookUri ? [$this->book($user, $bookUri)] : $this->books($user);
        $out = '';
        foreach ($books as $b) {
            foreach (DB::table('dav_cards')->where('addressbookid', $b['id'])->pluck('carddata') as $data) {
                $out .= rtrim((string) $data) . "\r\n";
            }
        }

        return $out;
    }

    /** Все адреса из книг пользователя для автодополнения. @return array<int,array{mail:string,name:string,kind:string}> */
    public function suggest(string $user, string $q, int $limit = 8): array
    {
        $q = mb_strtolower(trim($q));
        $out = [];
        foreach ($this->cards($user, null, $q) as $c) {
            foreach ($c['emails'] as $e) {
                if (! isset($out[$e['value']])) {
                    $out[$e['value']] = ['mail' => $e['value'], 'name' => $c['fn'], 'kind' => $c['book'] === 'employees' ? 'employee' : 'contact'];
                }
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_values($out);
    }

    // ── Календари ────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function calendars(string $user): array
    {
        $this->ensureOnce($user);
        $principal = Server::principal($user);
        $admin = $this->isAdmin($user);
        $out = [];
        foreach ($this->cals->getCalendarsForUser($principal) as $c) {
            [$calId, $instId] = $c['id'];
            $access = (int) ($c['share-access'] ?? Sharing::ACCESS_SHAREDOWNER);
            $system = ! empty($c['system']);
            $owner = null;
            if (! $system && $access !== Sharing::ACCESS_SHAREDOWNER) {
                $ownerUri = DB::table('dav_calendarinstances')->where('calendarid', $calId)->where('access', 1)->value('principaluri');
                $ownerMail = $ownerUri ? substr($ownerUri, strlen('principals/')) : null;
                $owner = $ownerMail ? ['mail' => $ownerMail, 'name' => $this->displayName($ownerMail)] : null;
            }
            $out[] = [
                'id' => $calId,
                'instance' => $instId,
                'uri' => $c['uri'],
                'name' => $c['{DAV:}displayname'] ?: $c['uri'],
                'color' => $c['{http://apple.com/ns/ical/}calendar-color'] ?: '#2F6FEB',
                'kind' => $system ? 'company' : ($access === Sharing::ACCESS_SHAREDOWNER ? ($c['uri'] === self::PERSONAL ? 'personal' : 'own') : 'shared'),
                'readonly' => $system ? ! $admin : $access === Sharing::ACCESS_READ,
                'owner' => $owner,
                'order' => (int) ($c['{http://apple.com/ns/ical/}calendar-order'] ?? 0),
            ];
        }
        usort($out, fn ($a, $b) => ['personal' => 0, 'own' => 1, 'shared' => 2, 'company' => 3][$a['kind']] <=> ['personal' => 0, 'own' => 1, 'shared' => 2, 'company' => 3][$b['kind']] ?: $a['order'] <=> $b['order']);

        return $out;
    }

    private function calendar(string $user, string $uri): array
    {
        foreach ($this->calendars($user) as $c) {
            if ($c['uri'] === $uri) {
                return $c;
            }
        }
        throw new DavException('Календарь не найден', 404);
    }

    public function createCalendar(string $user, string $name, string $color): array
    {
        $uri = 'cal-' . substr(md5(uniqid('', true)), 0, 10);
        $this->cals->createCalendar(Server::principal($user), $uri, [
            '{DAV:}displayname' => trim($name) ?: 'Календарь',
            '{http://apple.com/ns/ical/}calendar-color' => $color ?: '#2F6FEB',
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT', 'VTODO']),
        ]);

        return $this->calendar($user, $uri);
    }

    public function updateCalendar(string $user, string $uri, ?string $name, ?string $color): array
    {
        $c = $this->calendar($user, $uri);
        if ($c['readonly'] || $c['kind'] === 'shared') {
            throw new DavException('Этот календарь нельзя переименовать', 403);
        }
        $props = [];
        if ($name !== null) {
            $props['{DAV:}displayname'] = trim($name) ?: $c['name'];
        }
        if ($color !== null) {
            $props['{http://apple.com/ns/ical/}calendar-color'] = $color;
        }
        $patch = new \Sabre\DAV\PropPatch($props);
        $this->cals->systemWrites = $this->isAdmin($user);
        $this->cals->updateCalendar([$c['id'], $c['instance']], $patch);
        $patch->commit();

        return $this->calendar($user, $uri);
    }

    public function deleteCalendar(string $user, string $uri): void
    {
        $c = $this->calendar($user, $uri);
        if ($c['kind'] === 'personal' || $c['kind'] === 'company') {
            throw new DavException('Основной календарь удалить нельзя', 403);
        }
        // Чужой расшаренный календарь — просто отписаться (sabre удаляет только свою строку доступа).
        $this->dav($user, 'DELETE', "calendars/{$user}/{$uri}/");
    }

    /**
     * События всех видимых календарей в интервале.
     *
     * @param  string[]  $calendarUris  пустой список — все
     * @return array<int,array<string,mixed>>
     */
    public function events(string $user, DateTimeInterface $from, DateTimeInterface $to, array $calendarUris = []): array
    {
        $out = [];
        foreach ($this->calendars($user) as $c) {
            if ($calendarUris && ! in_array($c['uri'], $calendarUris, true)) {
                continue;
            }
            foreach ($this->objectsInRange([$c['id'], $c['instance']], $from, $to) as $row) {
                $meta = ['id' => $row['uri'], 'calendar' => $c['uri'], 'calendarName' => $c['name'], 'color' => $c['color'], 'readonly' => $c['readonly'], 'etag' => $row['etag'] ?? null];
                foreach (Events::occurrences((string) $row['calendardata'], $from, $to, $meta) as $item) {
                    $item['mine'] = ! $item['organizer'] || $item['organizer']['mail'] === strtolower($user);
                    $item['myStatus'] = null;
                    foreach ($item['attendees'] as $a) {
                        if ($a['mail'] === strtolower($user)) {
                            $item['myStatus'] = $a['status'];
                        }
                    }
                    $out[] = $item;
                }
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['start'], $b['start']));

        return $out;
    }

    /** @return array<int,array<string,mixed>> строки calendarobjects, пересекающие интервал */
    private function objectsInRange(array $calendarId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [[
                'name' => 'VEVENT', 'comp-filters' => [], 'prop-filters' => [], 'is-not-defined' => false,
                'time-range' => ['start' => DateTimeImmutable::createFromInterface($from), 'end' => DateTimeImmutable::createFromInterface($to)],
            ]],
            'prop-filters' => [], 'is-not-defined' => false, 'time-range' => null,
        ];
        $uris = $this->cals->calendarQuery($calendarId, $filters);
        if (! $uris) {
            return [];
        }

        return $this->cals->getMultipleCalendarObjects($calendarId, $uris);
    }

    public function event(string $user, string $calUri, string $objUri): array
    {
        $c = $this->calendar($user, $calUri);
        $row = $this->cals->getCalendarObject([$c['id'], $c['instance']], $objUri);
        if (! $row) {
            throw new DavException('Событие не найдено', 404);
        }
        $vcal = \Sabre\VObject\Reader::read((string) $row['calendardata'], \Sabre\VObject\Reader::OPTION_FORGIVING);
        $master = Events::master($vcal);
        $item = $master ? Events::summary($master, $master, ['id' => $objUri, 'calendar' => $calUri, 'calendarName' => $c['name'], 'color' => $c['color'], 'readonly' => $c['readonly'], 'etag' => $row['etag'] ?? null]) : null;
        if (! $item) {
            throw new DavException('Событие повреждено', 422);
        }
        $item['recurring'] = $item['rrule'] !== null;
        $item['mine'] = ! $item['organizer'] || $item['organizer']['mail'] === strtolower($user);
        $item['raw'] = (string) $row['calendardata'];

        return $item;
    }

    /** Создать или обновить событие. */
    public function saveEvent(string $user, string $calUri, ?string $objUri, array $data): array
    {
        $c = $this->calendar($user, $calUri);
        $existing = null;
        if ($objUri) {
            $row = $this->cals->getCalendarObject([$c['id'], $c['instance']], $objUri);
            $existing = $row ? (string) $row['calendardata'] : null;
        }
        $ics = Events::build($data, strtolower($user), $this->displayName($user), $existing);
        $objUri = $objUri ?: (string) \Illuminate\Support\Str::uuid() . '.ics';
        $this->dav($user, 'PUT', "calendars/{$user}/{$calUri}/{$objUri}", $ics, ['Content-Type' => 'text/calendar; charset=utf-8']);

        return $this->event($user, $calUri, $objUri);
    }

    /** Удалить событие или одно его вхождение ($occurrence — ISO-дата вхождения). */
    public function deleteEvent(string $user, string $calUri, string $objUri, ?string $occurrence = null): void
    {
        if ($occurrence) {
            $c = $this->calendar($user, $calUri);
            $row = $this->cals->getCalendarObject([$c['id'], $c['instance']], $objUri);
            if ($row) {
                $ics = Events::exclude((string) $row['calendardata'], $occurrence);
                $this->dav($user, 'PUT', "calendars/{$user}/{$calUri}/{$objUri}", $ics, ['Content-Type' => 'text/calendar; charset=utf-8']);

                return;
            }
        }
        $this->dav($user, 'DELETE', "calendars/{$user}/{$calUri}/{$objUri}");
    }

    /** Ответ на приглашение: ACCEPTED / DECLINED / TENTATIVE. */
    public function respond(string $user, string $calUri, string $objUri, string $partstat): array
    {
        $c = $this->calendar($user, $calUri);
        $row = $this->cals->getCalendarObject([$c['id'], $c['instance']], $objUri);
        if (! $row) {
            throw new DavException('Событие не найдено', 404);
        }
        $ics = Events::respond((string) $row['calendardata'], $user, $partstat);
        $this->dav($user, 'PUT', "calendars/{$user}/{$calUri}/{$objUri}", $ics, ['Content-Type' => 'text/calendar; charset=utf-8']);

        return $this->event($user, $calUri, $objUri);
    }

    /**
     * Занятость сотрудников: только интервалы, без названий (политика компании — занятость видна всем).
     *
     * @param  string[]  $users
     * @return array<string,array<int,array{start:string,end:string}>>
     */
    public function freeBusy(array $users, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $out = [];
        foreach ($users as $u) {
            $u = strtolower(trim($u));
            $out[$u] = [];
            $instances = DB::table('dav_calendarinstances')->where('principaluri', Server::principal($u))->where('access', 1)->get(['id', 'calendarid']);
            foreach ($instances as $inst) {
                foreach ($this->objectsInRange([(int) $inst->calendarid, (int) $inst->id], $from, $to) as $row) {
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

    // ── Совместный доступ к календарю ───────────────────────────────────

    /** @return array<int,array{mail:string,name:string,level:string}> */
    public function shares(string $user, string $calUri): array
    {
        $c = $this->calendar($user, $calUri);
        $out = [];
        foreach ($this->cals->getInvites([$c['id'], $c['instance']]) as $sharee) {
            if (in_array($sharee->access, [Sharing::ACCESS_READ, Sharing::ACCESS_READWRITE], true)) {
                $mail = strtolower(preg_replace('/^mailto:/i', '', $sharee->href));
                $out[] = ['mail' => $mail, 'name' => $this->displayName($mail), 'level' => $sharee->access === Sharing::ACCESS_READWRITE ? 'write' : 'read'];
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
        $this->ensureUser($with);
        $this->cals->updateInvites([$c['id'], $c['instance']], [new Sharee([
            'href' => 'mailto:' . $with,
            'principal' => Server::principal($with),
            'access' => $level === 'write' ? Sharing::ACCESS_READWRITE : Sharing::ACCESS_READ,
            'inviteStatus' => Sharing::INVITE_ACCEPTED,
            'properties' => ['{DAV:}displayname' => $this->displayName($with)],
        ])]);

        return $this->shares($user, $calUri);
    }

    public function unshare(string $user, string $calUri, string $with): array
    {
        $c = $this->calendar($user, $calUri);
        $this->cals->updateInvites([$c['id'], $c['instance']], [new Sharee([
            'href' => 'mailto:' . strtolower(trim($with)),
            'principal' => Server::principal($with),
            'access' => Sharing::ACCESS_NOACCESS,
        ])]);

        return $this->shares($user, $calUri);
    }

    // ── Общие книги (для админки и синхронизации сотрудников) ──────────

    public function systemCards(): CardBackend
    {
        $this->cards->systemWrites = true;

        return $this->cards;
    }

    public function systemBookId(string $uri): int
    {
        $id = array_search($uri, $this->cards->systemBooks(), true);
        if ($id === false) {
            throw new DavException("Общей книги «{$uri}» нет", 404);
        }

        return (int) $id;
    }
}
