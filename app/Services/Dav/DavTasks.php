<?php

namespace App\Services\Dav;

use DateTimeImmutable;

/** Задачи (VTODO): свой разбор, потому что формат отличается от событий. */
class DavTasks
{
    public function __construct(
        private readonly DavAccess $a,
        private readonly Calendars $cal,
    ) {
    }


    // ── Задачи (VTODO) ───────────────────────────────────────────────────

    /** Все задачи из календарей, где можно писать (свой личный, свои, отдела). @return array<int,array<string,mixed>> */
    public function tasks(string $user): array
    {
        $out = [];
        foreach ($this->calendars($user) as $c) {
            if ($c['readonly'] || $c['kind'] === 'company') {
                continue;
            }
            $filters = ['name' => 'VCALENDAR', 'comp-filters' => [['name' => 'VTODO', 'comp-filters' => [], 'prop-filters' => [], 'is-not-defined' => false, 'time-range' => null]], 'prop-filters' => [], 'is-not-defined' => false, 'time-range' => null];
            $uris = $this->a->cals->calendarQuery([$c['id'], $c['instance']], $filters);
            if (! $uris) {
                continue;
            }
            foreach ($this->a->cals->getMultipleCalendarObjects([$c['id'], $c['instance']], $uris) as $row) {
                $t = $this->parseTask((string) $row['calendardata']);
                if ($t) {
                    $out[] = $t + ['id' => $row['uri'], 'calendar' => $c['uri'], 'calendarName' => $c['name'], 'color' => $c['color']];
                }
            }
        }
        usort($out, fn ($a, $b) => [$a['done'], $a['due'] ?? '9999', $a['created'] ?? ''] <=> [$b['done'], $b['due'] ?? '9999', $b['created'] ?? '']);

        return $out;
    }


    public function saveTask(string $user, string $calUri, ?string $objUri, array $data): array
    {
        $c = $this->cal->calendar($user, $calUri);
        if ($c['readonly']) {
            throw new DavException('В этот календарь нельзя писать', 403);
        }
        $existing = null;
        if ($objUri) {
            $row = $this->a->cals->getCalendarObject([$c['id'], $c['instance']], $objUri);
            $existing = $row ? (string) $row['calendardata'] : null;
        }
        $vcal = $existing ? \Sabre\VObject\Reader::read($existing, \Sabre\VObject\Reader::OPTION_FORGIVING) : new \Sabre\VObject\Component\VCalendar();
        $todo = $existing ? ($vcal->VTODO ?? null) : null;
        if (! $todo) {
            $todo = $vcal->add('VTODO', ['UID' => (string) \Illuminate\Support\Str::uuid(), 'DTSTAMP' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'CREATED' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))]);
        }
        if (array_key_exists('title', $data)) {
            $todo->SUMMARY = mb_substr(trim((string) $data['title']), 0, 500) ?: 'Задача';
        }
        if (array_key_exists('description', $data)) {
            unset($todo->DESCRIPTION);
            if (filled($data['description'])) {
                $todo->DESCRIPTION = (string) $data['description'];
            }
        }
        if (array_key_exists('priority', $data)) {
            unset($todo->PRIORITY);
            if ((int) $data['priority'] > 0) {
                $todo->PRIORITY = (int) $data['priority'];
            }
        }
        if (array_key_exists('due', $data)) {
            unset($todo->DUE);
            if (filled($data['due'])) {
                $due = new \DateTimeImmutable((string) $data['due'], Events::tz());
                $prop = $todo->add('DUE', $due);
                if (strlen((string) $data['due']) <= 10) {
                    $prop['VALUE'] = 'DATE';
                }
            }
        }
        if (array_key_exists('done', $data)) {
            unset($todo->STATUS, $todo->COMPLETED, $todo->{'PERCENT-COMPLETE'});
            if ($data['done']) {
                $todo->STATUS = 'COMPLETED';
                $todo->COMPLETED = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $todo->{'PERCENT-COMPLETE'} = 100;
            } else {
                $todo->STATUS = 'NEEDS-ACTION';
            }
        }
        $todo->{'LAST-MODIFIED'} = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $objUri = $objUri ?: (string) \Illuminate\Support\Str::uuid() . '.ics';
        $this->a->dav($user, 'PUT', "calendars/{$user}/{$calUri}/{$objUri}", $vcal->serialize(), ['Content-Type' => 'text/calendar; charset=utf-8']);
        $row = $this->a->cals->getCalendarObject([$c['id'], $c['instance']], $objUri);

        return $this->parseTask((string) $row['calendardata']) + ['id' => $objUri, 'calendar' => $calUri, 'calendarName' => $c['name'], 'color' => $c['color']];
    }


    public function deleteTask(string $user, string $calUri, string $objUri): void
    {
        $c = $this->cal->calendar($user, $calUri);
        if ($c['readonly']) {
            throw new DavException('В этот календарь нельзя писать', 403);
        }
        $this->a->dav($user, 'DELETE', "calendars/{$user}/{$calUri}/{$objUri}");
    }


    /** @return array<string,mixed>|null */
    private function parseTask(string $ics): ?array
    {
        try {
            $vcal = \Sabre\VObject\Reader::read($ics, \Sabre\VObject\Reader::OPTION_FORGIVING);
        } catch (\Throwable) {
            return null;
        }
        $t = $vcal->VTODO ?? null;
        if (! $t) {
            return null;
        }
        $due = null;
        $allDay = false;
        if (isset($t->DUE)) {
            $dt = $t->DUE->getDateTime(Events::tz());
            $allDay = ! $t->DUE->hasTime();
            $due = $allDay ? $dt->format('Y-m-d') : $dt->setTimezone(Events::tz())->format('Y-m-d\TH:i');
        }
        $status = strtoupper((string) ($t->STATUS ?? ''));

        return [
            'uid' => (string) $t->UID,
            'title' => (string) ($t->SUMMARY ?? ''),
            'description' => (string) ($t->DESCRIPTION ?? ''),
            'due' => $due,
            'allDay' => $allDay,
            'done' => $status === 'COMPLETED' || isset($t->COMPLETED),
            'priority' => (int) ((string) ($t->PRIORITY ?? 0)),
            'created' => isset($t->CREATED) ? $t->CREATED->getDateTime()->format(DATE_ATOM) : null,
        ];
    }
}
