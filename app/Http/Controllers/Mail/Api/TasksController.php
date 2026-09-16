<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Dav\DavException;
use App\Services\Dav\DavStore;
use App\Services\Mail\ImapSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Задачи (VTODO) в календарях пользователя: телефоны и Outlook видят их через CalDAV как обычные задачи. */
class TasksController extends Controller
{
    public function __construct(private readonly DavStore $store)
    {
    }

    public function index(ImapSession $imap): JsonResponse
    {
        return $this->guard(fn () => $this->store->tasks($imap->user()));
    }

    public function store(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $this->validated($request);

        return $this->guard(fn () => $this->store->saveTask($imap->user(), $data['calendar'] ?? DavStore::PERSONAL, null, $data), 201);
    }

    public function update(Request $request, ImapSession $imap, string $calendar, string $uri): JsonResponse
    {
        $data = $this->validated($request);

        return $this->guard(fn () => $this->store->saveTask($imap->user(), $calendar, $uri, $data));
    }

    public function destroy(ImapSession $imap, string $calendar, string $uri): JsonResponse
    {
        return $this->guard(function () use ($imap, $calendar, $uri) {
            $this->store->deleteTask($imap->user(), $calendar, $uri);

            return ['ok' => true];
        });
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'calendar' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:500'],
            'due' => ['nullable', 'string', 'max:40'],
            'done' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:9'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function guard(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return response()->json($fn(), $status);
        } catch (DavException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }
    }
}
