<?php

namespace App\Services\Dav;

use App\Dav\Server;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Sharing\Plugin as Sharing;

/** Календари и события: список календарей, выборка по диапазону, правка и перенос событий. */
class Calendars
{
    public function __construct(
        private readonly DavAccess $a,
    ) {
    }


    // ── Календари ────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function calendars(string $user): array
    {
        $this->a->ensureOnce($user);
        $principal = Server::principal($user);
        $admin = $this->a->isAdmin($user);
        $out = [];
        foreach ($this->a->cals->getCalendarsForUser($principal) as $c) {
            [$calId, $instId] = $c['id'];
            $access = (int) ($c['share-access'] ?? Sharing::ACCESS_SHAREDOWNER);
            $system = ! empty($c['system']);
            $owner = null;
            if (! $system && $access !== Sharing::ACCESS_SHAREDOWNER) {
                $ownerUri = DB::table('dav_calendarinstances')->where('calendarid', $calId)->where('access', 1)->value('principaluri');
                $ownerMail = $ownerUri ? substr($ownerUri, strlen('principals/')) : null;
                if ($ownerUri && str_starts_with($ownerUri, 'principals/units/')) {
                    $owner = ['mail' => null, 'name' => (string) DB::table('dav_principals')->where('uri', $ownerUri)->value('displayname'), 'unit' => true];
                } else {
                    $owner = $ownerMail ? ['mail' => $ownerMail, 'name' => $this->a->displayName($ownerMail)] : null;
                }
            }
            $out[] = [
                'id' => $calId,
                'instance' => $instId,
                'uri' => $c['uri'],
                'name' => $c['{DAV:}displayname'] ?: $c['uri'],
                'color' => $c['{http://apple.com/ns/ical/}calendar-color'] ?: '#2F6FEB',
                'kind' => $system ? 'company' : ($access === Sharing::ACCESS_SHAREDOWNER ? ($c['uri'] === DavAccess::PERSONAL ? 'personal' : 'own') : 'shared'),
                'readonly' => $system ? ! $admin : $access === Sharing::ACCESS_READ,
                'owner' => $owner,
                'order' => (int) ($c['{http://apple.com/ns/ical/}calendar-order'] ?? 0),
            ];
        }
        usort($out, fn ($a, $b) => ['personal' => 0, 'own' => 1, 'shared' => 2, 'company' => 3][$a['kind']] <=> ['personal' => 0, 'own' => 1, 'shared' => 2, 'company' => 3][$b['kind']] ?: $a['order'] <=> $b['order']);

        return $out;
    }


    public function calendar(string $user, string $uri): array
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
        $this->a->cals->createCalendar(Server::principal($user), $uri, [
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
        $this->a->cals->systemWrites = $this->a->isAdmin($user);
        $this->a->cals->updateCalendar([$c['id'], $c['instance']], $patch);
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
        $this->a->dav($user, 'DELETE', "calendars/{$user}/{$uri}/");
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
    public function objectsInRange(array $calendarId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [[
                'name' => 'VEVENT', 'comp-filters' => [], 'prop-filters' => [], 'is-not-defined' => false,
                'time-range' => ['start' => DateTimeImmutable::createFromInterface($from), 'end' => DateTimeImmutable::createFromInterface($to)],
            ]],
            'prop-filters' => [], 'is-not-defined' => false, 'time-range' => null,
        ];
        $uris = $this->a->cals->calendarQuery($calendarId, $filters);
        if (! $uris) {
            return [];
        }

        return $this->a->cals->getMultipleCalendarObjects($calendarId, $uris);
    }


    public function event(string $user, string $calUri, string $objUri): array
    {
        $c = $this->calendar($user, $calUri);
        $row = $this->a->cals->getCalendarObject([$c['id'], $c['instance']], $objUri);
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
            $row = $this->a->cals->getCalendarObject([$c['id'], $c['instance']], $objUri);
            $existing = $row ? (string) $row['calendardata'] : null;
        }
        $ics = Events::build($data, strtolower($user), $this->a->displayName($user), $existing);
        $objUri = $objUri ?: (string) \Illuminate\Support\Str::uuid() . '.ics';
        $this->a->dav($user, 'PUT', "calendars/{$user}/{$calUri}/{$objUri}", $ics, ['Content-Type' => 'text/calendar; charset=utf-8']);

        return $this->event($user, $calUri, $objUri);
    }


    /**
     * Перенести событие в другой календарь, оставив его тем же событием.
     *
     * Раньше перенос делался «создать заново и удалить»: у копии был новый UID, поэтому
     * ответы участников обнулялись, всем уходило повторное приглашение, а старое событие
     * оставалось в их календарях навсегда. Пишем тот же файл под тем же именем в другой
     * календарь и только потом убираем из прежнего.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function moveEvent(string $user, string $fromCal, string $toCal, string $objUri, array $data): array
    {
        $from = $this->calendar($user, $fromCal);
        $row = $this->a->cals->getCalendarObject([$from['id'], $from['instance']], $objUri);
        $existing = $row ? (string) $row['calendardata'] : null;
        $ics = Events::build($data, strtolower($user), $this->a->displayName($user), $existing);
        $this->a->dav($user, 'PUT', "calendars/{$user}/{$toCal}/{$objUri}", $ics, ['Content-Type' => 'text/calendar; charset=utf-8']);
        $this->a->dav($user, 'DELETE', "calendars/{$user}/{$fromCal}/{$objUri}");

        return $this->event($user, $toCal, $objUri);
    }


    /** Удалить событие или одно его вхождение ($occurrence — ISO-дата вхождения). */
    public function deleteEvent(string $user, string $calUri, string $objUri, ?string $occurrence = null): void
    {
        if ($occurrence) {
            $c = $this->calendar($user, $calUri);
            $row = $this->a->cals->getCalendarObject([$c['id'], $c['instance']], $objUri);
            if ($row) {
                $ics = Events::exclude((string) $row['calendardata'], $occurrence);
                $this->a->dav($user, 'PUT', "calendars/{$user}/{$calUri}/{$objUri}", $ics, ['Content-Type' => 'text/calendar; charset=utf-8']);

                return;
            }
        }
        $this->a->dav($user, 'DELETE', "calendars/{$user}/{$calUri}/{$objUri}");
    }


    /** Ответ на приглашение: ACCEPTED / DECLINED / TENTATIVE. */
    public function respond(string $user, string $calUri, string $objUri, string $partstat): array
    {
        $c = $this->calendar($user, $calUri);
        $row = $this->a->cals->getCalendarObject([$c['id'], $c['instance']], $objUri);
        if (! $row) {
            throw new DavException('Событие не найдено', 404);
        }
        $ics = Events::respond((string) $row['calendardata'], $user, $partstat);
        $this->a->dav($user, 'PUT', "calendars/{$user}/{$calUri}/{$objUri}", $ics, ['Content-Type' => 'text/calendar; charset=utf-8']);

        return $this->event($user, $calUri, $objUri);
    }
}
