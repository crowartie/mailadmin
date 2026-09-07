<?php

namespace App\Services\Dav;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * iCalendar ↔ массив для интерфейса. Время храним в UTC (так понимают все клиенты),
 * целодневные события — датами; наружу отдаём ISO 8601 в часовом поясе приложения.
 */
class Events
{
    /** Числовое свойство vobject → int (сам объект в int не приводится). */
    private static function int($prop): int
    {
        return $prop === null ? 0 : (int) (string) $prop;
    }

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone(config('app.timezone', 'UTC'));
    }

    /**
     * Все вхождения событий из .ics в интервале (повторяющиеся — раскрыты).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function occurrences(string $ics, DateTimeInterface $from, DateTimeInterface $to, array $meta = []): array
    {
        try {
            /** @var VCalendar $vcal */
            $vcal = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (\Throwable) {
            return [];
        }
        $master = self::master($vcal);
        if (! $master) {
            return [];
        }
        $recurring = isset($master->RRULE) || isset($master->RDATE);
        try {
            $expanded = $recurring ? $vcal->expand($from, $to, new DateTimeZone('UTC')) : $vcal;
        } catch (\Throwable) {
            $expanded = $vcal;
        }

        $out = [];
        foreach ($expanded->select('VEVENT') as $ev) {
            $item = self::summary($ev, $master, $meta);
            if (! $item) {
                continue;
            }
            // Без повторов sabre не фильтрует по времени — проверяем пересечение сами.
            $s = new DateTimeImmutable($item['start']);
            $e = new DateTimeImmutable($item['end']);
            if ($e <= $from || $s >= $to) {
                continue;
            }
            $item['recurring'] = $recurring;
            $out[] = $item;
        }

        return $out;
    }

    public static function master(VCalendar $vcal): ?VEvent
    {
        foreach ($vcal->select('VEVENT') as $ev) {
            if (! isset($ev->{'RECURRENCE-ID'})) {
                return $ev;
            }
        }
        $all = $vcal->select('VEVENT');

        return $all[0] ?? null;
    }

    /** @return array<string,mixed>|null */
    public static function summary(VEvent $ev, ?VEvent $master = null, array $meta = []): ?array
    {
        if (! isset($ev->DTSTART)) {
            return null;
        }
        $master ??= $ev;
        $tz = self::tz();
        $allDay = ! $ev->DTSTART->hasTime();
        $start = $ev->DTSTART->getDateTime($tz);
        if (isset($ev->DTEND)) {
            $end = $ev->DTEND->getDateTime($tz);
        } elseif (isset($ev->DURATION)) {
            $end = $start->add(\Sabre\VObject\DateTimeParser::parseDuration((string) $ev->DURATION));
        } else {
            $end = $allDay ? $start->modify('+1 day') : $start->modify('+1 hour');
        }
        if ($allDay) {
            $start = $start->setTimezone($tz)->setTime(0, 0);
            $end = $end->setTimezone($tz)->setTime(0, 0);
        }

        $attendees = [];
        foreach ($ev->select('ATTENDEE') as $a) {
            $mail = strtolower(preg_replace('/^mailto:/i', '', (string) $a));
            $attendees[] = [
                'mail' => $mail,
                'name' => (string) ($a['CN'] ?? '') ?: $mail,
                'status' => strtoupper((string) ($a['PARTSTAT'] ?? 'NEEDS-ACTION')),
                'role' => strtoupper((string) ($a['ROLE'] ?? 'REQ-PARTICIPANT')),
            ];
        }
        $organizer = null;
        if (isset($ev->ORGANIZER)) {
            $mail = strtolower(preg_replace('/^mailto:/i', '', (string) $ev->ORGANIZER));
            $organizer = ['mail' => $mail, 'name' => (string) ($ev->ORGANIZER['CN'] ?? '') ?: $mail];
        }

        $alarm = null;
        foreach ($ev->select('VALARM') as $va) {
            if (isset($va->TRIGGER) && preg_match('/^-P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?$/', (string) $va->TRIGGER, $m)) {
                $alarm = ((int) ($m[1] ?? 0)) * 1440 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);
                break;
            }
        }

        $rrule = null;
        if (isset($master->RRULE)) {
            $parts = $master->RRULE->getParts();
            $rrule = [
                'freq' => strtoupper((string) ($parts['FREQ'] ?? 'DAILY')),
                'interval' => (int) ($parts['INTERVAL'] ?? 1),
                'until' => isset($parts['UNTIL']) ? substr((string) $parts['UNTIL'], 0, 8) : null,
                'count' => isset($parts['COUNT']) ? (int) $parts['COUNT'] : null,
                'byday' => isset($parts['BYDAY']) ? (array) $parts['BYDAY'] : [],
            ];
            if ($rrule['until']) {
                $rrule['until'] = substr($rrule['until'], 0, 4) . '-' . substr($rrule['until'], 4, 2) . '-' . substr($rrule['until'], 6, 2);
            }
        }

        return array_merge($meta, [
            'uid' => (string) $ev->UID,
            'title' => trim((string) ($ev->SUMMARY ?? '')) ?: '(без названия)',
            'start' => $start->format(DateTimeInterface::ATOM),
            'end' => $end->format(DateTimeInterface::ATOM),
            'allDay' => $allDay,
            'location' => (string) ($ev->LOCATION ?? ''),
            'description' => (string) ($ev->DESCRIPTION ?? ''),
            'url' => (string) ($ev->URL ?? ''),
            'status' => strtoupper((string) ($ev->STATUS ?? 'CONFIRMED')),
            'transparent' => strtoupper((string) ($ev->TRANSP ?? 'OPAQUE')) === 'TRANSPARENT',
            'organizer' => $organizer,
            'attendees' => $attendees,
            'alarm' => $alarm,
            'rrule' => $rrule,
            'recurrenceId' => isset($ev->{'RECURRENCE-ID'}) ? $ev->{'RECURRENCE-ID'}->getDateTime($tz)->format(DateTimeInterface::ATOM) : null,
            'sequence' => self::int($ev->SEQUENCE ?? null),
        ]);
    }

    /**
     * Собрать .ics из формы. $existing — прежний объект (сохраняем UID, EXDATE, статусы участников).
     *
     * @param  array<string,mixed>  $e
     */
    public static function build(array $e, string $user, string $userName, ?string $existing = null): string
    {
        $old = null;
        if ($existing) {
            try {
                $oldCal = Reader::read($existing, Reader::OPTION_FORGIVING);
                $old = $oldCal instanceof VCalendar ? self::master($oldCal) : null;
            } catch (\Throwable) {
                $old = null;
            }
        }

        $vcal = new VCalendar();
        $vcal->PRODID = '-//Почта ' . config('areas.default_domain') . '//RU';
        $uid = $old ? (string) $old->UID : (string) \Illuminate\Support\Str::uuid();

        /** @var VEvent $ev */
        $ev = $vcal->add('VEVENT', ['UID' => $uid, 'SUMMARY' => trim((string) ($e['title'] ?? '')) ?: '(без названия)']);
        $ev->DTSTAMP = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $ev->add('CREATED', $old && isset($old->CREATED) ? $old->CREATED->getDateTime() : new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $ev->add('LAST-MODIFIED', new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $ev->SEQUENCE = $old ? self::int($old->SEQUENCE ?? null) + 1 : 0;

        $tz = self::tz();
        if (! empty($e['allDay'])) {
            $start = new DateTimeImmutable(substr((string) $e['start'], 0, 10), $tz);
            $end = new DateTimeImmutable(substr((string) ($e['end'] ?? $e['start']), 0, 10), $tz);
            if ($end < $start) {
                $end = $start;
            }
            $ev->add('DTSTART', $start, ['VALUE' => 'DATE']);
            $ev->add('DTEND', $end->modify('+1 day'), ['VALUE' => 'DATE']);   // DTEND у целодневных — исключающий
        } else {
            $start = (new DateTimeImmutable((string) $e['start'], $tz))->setTimezone(new DateTimeZone('UTC'));
            $end = (new DateTimeImmutable((string) ($e['end'] ?? $e['start']), $tz))->setTimezone(new DateTimeZone('UTC'));
            if ($end <= $start) {
                $end = $start->modify('+30 minutes');
            }
            $ev->DTSTART = $start;
            $ev->DTEND = $end;
        }

        foreach (['location' => 'LOCATION', 'description' => 'DESCRIPTION', 'url' => 'URL'] as $key => $prop) {
            if (filled($e[$key] ?? null)) {
                $ev->add($prop, trim((string) $e[$key]));
            }
        }
        $ev->STATUS = in_array($e['status'] ?? '', ['TENTATIVE', 'CANCELLED'], true) ? $e['status'] : 'CONFIRMED';
        $ev->TRANSP = ! empty($e['transparent']) ? 'TRANSPARENT' : 'OPAQUE';

        if (! empty($e['rrule']['freq'])) {
            $r = $e['rrule'];
            $parts = ['FREQ=' . strtoupper($r['freq']), 'INTERVAL=' . max(1, (int) ($r['interval'] ?? 1))];
            if (! empty($r['byday']) && strtoupper($r['freq']) === 'WEEKLY') {
                $parts[] = 'BYDAY=' . implode(',', array_map('strtoupper', (array) $r['byday']));
            }
            if (! empty($r['until'])) {
                $until = (new DateTimeImmutable($r['until'] . ' 23:59:59', $tz))->setTimezone(new DateTimeZone('UTC'));
                $parts[] = 'UNTIL=' . ($ev->DTSTART->hasTime() ? $until->format('Ymd\THis\Z') : $until->format('Ymd'));
            } elseif (! empty($r['count'])) {
                $parts[] = 'COUNT=' . (int) $r['count'];
            }
            $ev->add('RRULE', implode(';', $parts));
            if ($old) {
                foreach ($old->select('EXDATE') as $ex) {
                    $ev->add(clone $ex);
                }
            }
        }

        $attendees = array_values(array_filter((array) ($e['attendees'] ?? []), fn ($a) => filled($a['mail'] ?? null)));
        if ($attendees) {
            $oldStatus = [];
            if ($old) {
                foreach ($old->select('ATTENDEE') as $a) {
                    $oldStatus[strtolower(preg_replace('/^mailto:/i', '', (string) $a))] = (string) ($a['PARTSTAT'] ?? 'NEEDS-ACTION');
                }
            }
            $organizer = $old && isset($old->ORGANIZER) ? strtolower(preg_replace('/^mailto:/i', '', (string) $old->ORGANIZER)) : $user;
            $ev->add('ORGANIZER', 'mailto:' . $organizer, ['CN' => $organizer === $user ? $userName : ($old->ORGANIZER['CN'] ?? $organizer)]);
            foreach ($attendees as $a) {
                $mail = strtolower(trim($a['mail']));
                $ev->add('ATTENDEE', 'mailto:' . $mail, [
                    'CN' => trim((string) ($a['name'] ?? '')) ?: $mail,
                    'ROLE' => strtoupper($a['role'] ?? 'REQ-PARTICIPANT'),
                    'PARTSTAT' => $oldStatus[$mail] ?? 'NEEDS-ACTION',
                    'RSVP' => 'TRUE',
                    'CUTYPE' => 'INDIVIDUAL',
                ]);
            }
        }

        if (isset($e['alarm']) && $e['alarm'] !== '' && $e['alarm'] !== null && (int) $e['alarm'] >= 0) {
            $min = (int) $e['alarm'];
            $trigger = $min === 0 ? 'PT0M' : ($min % 1440 === 0 ? '-P' . intdiv($min, 1440) . 'D' : ($min % 60 === 0 ? '-PT' . intdiv($min, 60) . 'H' : '-PT' . $min . 'M'));
            $alarm = $ev->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => (string) $ev->SUMMARY]);
            $alarm->add('TRIGGER', $trigger, ['VALUE' => 'DURATION']);
        }

        return $vcal->serialize();
    }

    /** Исключить одно вхождение повторяющегося события (EXDATE). */
    public static function exclude(string $ics, string $occurrenceIso): string
    {
        $vcal = Reader::read($ics, Reader::OPTION_FORGIVING);
        $master = self::master($vcal);
        $dt = new DateTimeImmutable($occurrenceIso);
        if ($master->DTSTART->hasTime()) {
            $master->add('EXDATE', $dt->setTimezone(new DateTimeZone('UTC')));
        } else {
            $master->add('EXDATE', $dt->setTimezone(self::tz()), ['VALUE' => 'DATE']);
        }
        $master->SEQUENCE = self::int($master->SEQUENCE ?? null) + 1;

        return $vcal->serialize();
    }

    /** Ответ участника: проставить PARTSTAT своему ATTENDEE. */
    public static function respond(string $ics, string $user, string $partstat): string
    {
        $vcal = Reader::read($ics, Reader::OPTION_FORGIVING);
        foreach ($vcal->select('VEVENT') as $ev) {
            foreach ($ev->select('ATTENDEE') as $a) {
                if (strtolower(preg_replace('/^mailto:/i', '', (string) $a)) === strtolower($user)) {
                    $a['PARTSTAT'] = $partstat;
                    unset($a['RSVP']);
                }
            }
        }

        return $vcal->serialize();
    }
}
