<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    /** Метка «до какого времени календарь можно не открывать», по ящикам. */
    public const ALARM_KEY = 'cal.quiet.';

    /**
     * Дольше пяти минут молчать нельзя: встречу с будильником может завести коллега
     * в общем календаре, а про его правку мы не узнаём.
     */
    private const ALARM_MAX_QUIET = 300;

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
        // У любой папки общего ящика роль «общая», а настоящая роль лежит в srole:
        // из-за этого очистка корзины коллеги отвечала «Очищать можно только корзину и спам».
        $f = collect($store->folders())->firstWhere('path', $folder) ?? [];
        $role = ($f['role'] ?? 'custom') === 'shared' ? ($f['srole'] ?? 'custom') : ($f['role'] ?? 'custom');
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
        $reminders = $this->dueReminders($imap->user());

        return response()->json(['folder' => $st, 'inboxUnseen' => $inbox['unseen'], 'reminders' => $reminders, 'at' => now()->toIso8601String()]);
    }

    /**
     * Напоминания о встречах, которым пора сработать.
     *
     * Это самая дорогая часть опроса «есть ли новая почта»: 40 мс из 69, при том что опрос
     * идёт раз в 20 секунд у каждой открытой вкладки — 48 тысяч раз в сутки. Перебирать
     * ради этого все события на двое суток вперёд каждый раз незачем: будильник срабатывает
     * несколько раз в день.
     *
     * Поэтому запоминаем, когда ближайший будильник, и до него календарь не трогаем.
     * Метка сбрасывается при любой правке события (см. Calendars::forgetAlarms), а живёт
     * в любом случае не дольше пяти минут — чтобы встреча, заведённая коллегой в общем
     * календаре, не осталась незамеченной.
     */
    private function dueReminders(string $user): array
    {
        $key = self::ALARM_KEY . $user;
        $now = now();
        $skipUntil = \Illuminate\Support\Facades\Cache::get($key);
        if ($skipUntil && $now->getTimestamp() < (int) $skipUntil) {
            return [];
        }

        $due = [];
        $next = null;
        try {
            $dav = app(\App\Services\Dav\DavStore::class);
            foreach ($dav->events($user, $now->copy()->subMinutes(5), $now->copy()->addDays(2)) as $e) {
                if (! isset($e['alarm']) || $e['alarm'] === null || empty($e['start'])) {
                    continue;
                }
                $trigger = \Carbon\Carbon::parse($e['start'])->subMinutes((int) $e['alarm']);
                if ($trigger->between($now->copy()->subMinutes(2), $now->copy()->addSeconds(30))) {
                    $due[] = ['key' => ($e['uid'] ?? $e['id']) . '@' . $e['start'], 'title' => $e['title'] ?? '', 'start' => $e['start'], 'allDay' => (bool) ($e['allDay'] ?? false), 'location' => $e['location'] ?? ''];
                } elseif ($trigger->isAfter($now) && ($next === null || $trigger->lt($next))) {
                    $next = $trigger;
                }
            }
        } catch (\Throwable) {
            // календарь недоступен — только почта; и не запоминаем, чтобы попробовать снова
            return [];
        }

        // До ближайшего будильника минус минута календарь можно не открывать.
        $quietFor = $next ? max(0, $next->diffInSeconds($now, false) * -1 - 60) : self::ALARM_MAX_QUIET;
        $quietFor = (int) min($quietFor, self::ALARM_MAX_QUIET);
        if ($quietFor > 0) {
            \Illuminate\Support\Facades\Cache::put($key, $now->getTimestamp() + $quietFor, $quietFor);
        }

        return $due;
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
        $data = $request->validate(['with' => ['required', 'email'], 'level' => ['required', 'in:reader,editor,owner']]);
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
        // Иначе значок «открыта коллегам» появлялся только через минуту.
        MailStore::forgetSharesCache($imap->user());

        return response()->json(['shares' => $svc->list($imap->user(), \App\Services\Mail\FolderShares::utf8($folder)), 'folders' => (new MailStore($imap->client()))->folders()]);
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
        MailStore::forgetSharesCache($imap->user());

        return response()->json(['shares' => $svc->list($imap->user(), \App\Services\Mail\FolderShares::utf8($folder)), 'folders' => (new MailStore($imap->client()))->folders()]);
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
        $f = collect($store->folders())->firstWhere('path', $folder) ?? [];
        $shared = ($f['role'] ?? 'custom') === 'shared';
        // Своя подпапка в общем ящике — не системная: раньше переименование любой папки
        // коллеги отвечало «Системную папку нельзя переименовать или удалить».
        $role = $shared ? ($f['srole'] ?? 'custom') : ($f['role'] ?? 'custom');
        abort_if($role !== 'custom', 422, $shared
            ? 'Это системная папка чужого ящика — переименовать или удалить её может только владелец'
            : 'Системную папку нельзя переименовать или удалить');
    }
}
