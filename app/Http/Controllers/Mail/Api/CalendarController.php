<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Dav\DavException;
use App\Services\Dav\DavStore;
use App\Services\Mail\ImapSession;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function __construct(private readonly DavStore $store)
    {
    }

    public function calendars(ImapSession $imap): JsonResponse
    {
        return response()->json($this->store->calendars($imap->user()));
    }

    public function storeCalendar(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);

        return $this->guard(fn () => $this->store->createCalendar($imap->user(), $data['name'], $data['color'] ?? '#2F6FEB'), 201);
    }

    public function updateCalendar(Request $request, ImapSession $imap, string $calendar): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:100'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);

        return $this->guard(fn () => $this->store->updateCalendar($imap->user(), $calendar, $data['name'] ?? null, $data['color'] ?? null));
    }

    public function destroyCalendar(ImapSession $imap, string $calendar): JsonResponse
    {
        return $this->guard(function () use ($imap, $calendar) {
            $this->store->deleteCalendar($imap->user(), $calendar);

            return ['ok' => true];
        });
    }

    public function events(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date'], 'calendars' => ['nullable', 'string']]);
        $uris = array_values(array_filter(explode(',', (string) ($data['calendars'] ?? ''))));

        return $this->guard(fn () => $this->store->events($imap->user(), new DateTimeImmutable($data['from']), new DateTimeImmutable($data['to']), $uris));
    }

    public function show(ImapSession $imap, string $calendar, string $uri): JsonResponse
    {
        return $this->guard(fn () => $this->strip($this->store->event($imap->user(), $calendar, $uri)));
    }

    public function store(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $this->validated($request);

        return $this->guard(fn () => $this->strip($this->store->saveEvent($imap->user(), $data['calendar'] ?? DavStore::PERSONAL, null, $data)), 201);
    }

    public function update(Request $request, ImapSession $imap, string $calendar, string $uri): JsonResponse
    {
        $data = $this->validated($request);

        return $this->guard(function () use ($imap, $calendar, $uri, $data) {
            $saved = $this->store->saveEvent($imap->user(), $calendar, $uri, $data);
            // Перенос в другой календарь: создать там, удалить здесь.
            if (! empty($data['calendar']) && $data['calendar'] !== $calendar) {
                $moved = $this->store->saveEvent($imap->user(), $data['calendar'], null, $data);
                $this->store->deleteEvent($imap->user(), $calendar, $uri);
                $saved = $moved;
            }

            return $this->strip($saved);
        });
    }

    public function destroy(Request $request, ImapSession $imap, string $calendar, string $uri): JsonResponse
    {
        return $this->guard(function () use ($request, $imap, $calendar, $uri) {
            $this->store->deleteEvent($imap->user(), $calendar, $uri, $request->input('occurrence') ?: null);

            return ['ok' => true];
        });
    }

    public function respond(Request $request, ImapSession $imap, string $calendar, string $uri): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:ACCEPTED,DECLINED,TENTATIVE']]);

        return $this->guard(fn () => $this->strip($this->store->respond($imap->user(), $calendar, $uri, $data['status'])));
    }

    public function freebusy(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate(['users' => ['required', 'string'], 'from' => ['required', 'date'], 'to' => ['required', 'date']]);
        $users = array_slice(array_values(array_filter(array_map('trim', explode(',', $data['users'])))), 0, 30);

        return $this->guard(fn () => $this->store->freeBusy($users, new DateTimeImmutable($data['from']), new DateTimeImmutable($data['to'])));
    }

    public function shares(ImapSession $imap, string $calendar): JsonResponse
    {
        return $this->guard(fn () => $this->store->shares($imap->user(), $calendar));
    }

    public function share(Request $request, ImapSession $imap, string $calendar): JsonResponse
    {
        $data = $request->validate(['with' => ['required', 'email'], 'level' => ['required', 'in:read,write']]);

        return $this->guard(fn () => $this->store->share($imap->user(), $calendar, $data['with'], $data['level']));
    }

    public function unshare(Request $request, ImapSession $imap, string $calendar): JsonResponse
    {
        $data = $request->validate(['with' => ['required', 'email']]);

        return $this->guard(fn () => $this->store->unshare($imap->user(), $calendar, $data['with']));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'calendar' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:255'],
            'start' => ['required', 'string', 'max:40'],
            'end' => ['nullable', 'string', 'max:40'],
            'allDay' => ['nullable', 'boolean'],
            'location' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'url' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:CONFIRMED,TENTATIVE,CANCELLED'],
            'transparent' => ['nullable', 'boolean'],
            'rrule' => ['nullable', 'array'],
            'rrule.freq' => ['nullable', 'in:DAILY,WEEKLY,MONTHLY,YEARLY'],
            'rrule.interval' => ['nullable', 'integer', 'min:1', 'max:99'],
            'rrule.until' => ['nullable', 'date_format:Y-m-d'],
            'rrule.count' => ['nullable', 'integer', 'min:1', 'max:999'],
            'rrule.byday' => ['nullable', 'array'],
            'attendees' => ['nullable', 'array', 'max:100'],
            'attendees.*.mail' => ['nullable', 'email'],
            'attendees.*.name' => ['nullable', 'string', 'max:255'],
            'alarm' => ['nullable', 'integer', 'min:0', 'max:20160'],
        ]);
    }

    private function strip(array $e): array
    {
        unset($e['raw']);

        return $e;
    }

    private function guard(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return response()->json($fn(), $status);
        } catch (DavException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
