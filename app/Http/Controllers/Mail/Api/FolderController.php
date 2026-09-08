<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function index(ImapSession $imap): JsonResponse
    {
        return response()->json((new MailStore($imap->client()))->folders());
    }

    public function store(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'parent' => ['nullable', 'string']]);
        $store = new MailStore($imap->client());
        $path = $store->createFolder($data['name'], $data['parent'] ?? null);

        return response()->json(['path' => $path, 'folders' => $store->folders()]);
    }

    public function update(Request $request, ImapSession $imap, string $folder): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $store = new MailStore($imap->client());
        $this->guardSystem($store, $folder);
        $path = $store->renameFolder($folder, $data['name']);

        return response()->json(['path' => $path, 'folders' => $store->folders()]);
    }

    public function destroy(ImapSession $imap, string $folder): JsonResponse
    {
        $store = new MailStore($imap->client());
        $this->guardSystem($store, $folder);
        $store->deleteFolder($folder);

        return response()->json(['folders' => $store->folders()]);
    }

    /** Очистить корзину или спам. */
    public function empty(ImapSession $imap, string $folder): JsonResponse
    {
        $store = new MailStore($imap->client());
        $role = collect($store->folders())->firstWhere('path', $folder)['role'] ?? 'custom';
        abort_unless(in_array($role, ['trash', 'spam']), 422, 'Очищать можно только корзину и спам');
        $store->emptyFolder($folder);

        return response()->json(['folders' => $store->folders()]);
    }

    /** Опрос «есть ли новое»: статус папки + напоминания календаря, которые пора показать. */
    public function status(Request $request, ImapSession $imap): JsonResponse
    {
        $folder = (string) $request->query('folder', 'INBOX');
        $store = new MailStore($imap->client());
        $st = $store->status($folder);
        $inbox = $folder === 'INBOX' ? $st : $store->status('INBOX');
        $reminders = [];
        try {
            $dav = app(\App\Services\Dav\DavStore::class);
            $now = now();
            foreach ($dav->events($imap->user(), $now->copy()->subMinutes(5), $now->copy()->addDays(2)) as $e) {
                if (! isset($e['alarm']) || $e['alarm'] === null || empty($e['start'])) {
                    continue;
                }
                $trigger = \Carbon\Carbon::parse($e['start'])->subMinutes((int) $e['alarm']);
                if ($trigger->between($now->copy()->subMinutes(2), $now->copy()->addSeconds(30))) {
                    $reminders[] = ['key' => ($e['uid'] ?? $e['id']) . '@' . $e['start'], 'title' => $e['title'] ?? '', 'start' => $e['start'], 'allDay' => (bool) ($e['allDay'] ?? false), 'location' => $e['location'] ?? ''];
                }
            }
        } catch (\Throwable) {
            // календарь недоступен — только почта
        }

        return response()->json(['folder' => $st, 'inboxUnseen' => $inbox['unseen'], 'reminders' => $reminders, 'at' => now()->toIso8601String()]);
    }

    /** Кому открыта папка + кандидаты. */
    public function shares(ImapSession $imap, string $folder): JsonResponse
    {
        $store = new MailStore($imap->client());
        $this->guardOwn($store, $folder);
        $svc = new \App\Services\Mail\FolderShares();

        return response()->json(['shares' => $svc->list($imap->user(), \App\Services\Mail\FolderShares::utf8($folder)), 'candidates' => \App\Services\Mail\FolderShares::candidates($imap->user())]);
    }

    public function share(Request $request, ImapSession $imap, string $folder): JsonResponse
    {
        $data = $request->validate(['with' => ['required', 'email'], 'level' => ['required', 'in:reader,editor']]);
        $store = new MailStore($imap->client());
        $this->guardOwn($store, $folder);
        $svc = new \App\Services\Mail\FolderShares();
        try {
            $svc->set($imap->user(), \App\Services\Mail\FolderShares::utf8($folder), $data['with'], $data['level']);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(500, 'Не удалось выдать доступ: ' . mb_substr($e->getMessage(), 0, 200));
        }

        return response()->json(['shares' => $svc->list($imap->user(), \App\Services\Mail\FolderShares::utf8($folder))]);
    }

    public function unshare(Request $request, ImapSession $imap, string $folder): JsonResponse
    {
        $data = $request->validate(['with' => ['required', 'email']]);
        $store = new MailStore($imap->client());
        $this->guardOwn($store, $folder);
        $svc = new \App\Services\Mail\FolderShares();
        try {
            $svc->remove($imap->user(), \App\Services\Mail\FolderShares::utf8($folder), $data['with']);
        } catch (\RuntimeException $e) {
            abort(500, 'Не удалось снять доступ: ' . mb_substr($e->getMessage(), 0, 200));
        }

        return response()->json(['shares' => $svc->list($imap->user(), \App\Services\Mail\FolderShares::utf8($folder))]);
    }

    /** Делиться можно только своими папками (не чужими общими и не в режиме администратора без прав). */
    private function guardOwn(MailStore $store, string $folder): void
    {
        $f = collect($store->folders())->firstWhere('path', $folder);
        abort_if(! $f, 404, 'Папка не найдена');
        abort_if(($f['role'] ?? '') === 'shared', 422, 'Чужой папкой поделиться нельзя');
    }

    private function guardSystem(MailStore $store, string $folder): void
    {
        $role = collect($store->folders())->firstWhere('path', $folder)['role'] ?? 'custom';
        abort_if($role !== 'custom', 422, 'Системную папку нельзя переименовать или удалить');
    }
}
