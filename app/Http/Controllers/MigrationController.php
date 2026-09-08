<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Models\AppSetting;
use App\Models\MailMigration;
use App\Models\Vmail\Mailbox;
use App\Services\Migration\Imapsync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Настройки → Перенос: переезд ящиков со старого сервера (Kerio Connect или любой IMAP).
 * Почта копируется imapsync'ом, контакты и календари — по CardDAV/CalDAV с теми же учётками.
 * Перенос можно гонять повторно: докачивается только новое, дублей не будет.
 */
class MigrationController extends Controller
{
    public function __construct(private readonly Imapsync $imapsync)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Settings/Migrate', [
            'tab' => 'migrate',
            'ctl' => \App\Services\Server\Ctl::available(),
            'available' => Imapsync::available(),
            'source' => AppSetting::group('migration'),
            'rows' => $this->rows(),
            'statuses' => MailMigration::STATUSES,
            'defaultDomain' => config('areas.default_domain'),
        ]);
    }

    private function rows(): array
    {
        return MailMigration::query()->orderBy('id')->get()->map(fn (MailMigration $m) => $this->row($m))->all();
    }

    private function row(MailMigration $m): array
    {
        return [
            'id' => $m->id, 'login' => $m->source_login, 'target' => $m->target, 'what' => $m->what,
            'status' => $m->status, 'statusTitle' => MailMigration::STATUSES[$m->status] ?? $m->status,
            'stats' => $m->stats, 'dav' => $m->dav_stats, 'error' => $m->error,
            'startedAt' => $m->started_at?->format('d.m H:i'), 'finishedAt' => $m->finished_at?->format('d.m H:i'),
            'progress' => $m->status === 'running' ? Imapsync::progress($m) : null,
            'hasLog' => (bool) ($m->log_path && is_file($m->log_path)),
        ];
    }

    /** Живой статус для страницы (опрос раз в несколько секунд, пока что-то идёт). */
    public function status(): JsonResponse
    {
        return response()->json(['rows' => $this->rows()]);
    }

    public function saveSource(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:200'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'ssl' => ['boolean'],
            'dav_url' => ['nullable', 'string', 'max:200'],
        ]);
        $data['ssl'] = (bool) ($data['ssl'] ?? true);
        $data['dav_url'] = trim((string) ($data['dav_url'] ?? ''));
        AppSetting::put('migration', $data);

        return back()->with('success', 'Старый сервер сохранён');
    }

    /**
     * Добавить ящики списком: «логин;пароль;куда». «Куда» можно не указывать —
     * возьмём наш ящик с тем же адресом или с той же частью до @ в основном домене.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['lines' => ['required', 'string', 'max:200000']]);
        $src = AppSetting::group('migration');
        if (blank($src['host'] ?? '')) {
            return back()->with('error', 'Сначала укажите адрес старого сервера');
        }
        $added = 0;
        $bad = [];
        foreach (preg_split('/\r?\n/', $data['lines']) as $n => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', preg_split('/[;\t]+| {2,}| (?=\S+@)/', $line));
            $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
            if (count($parts) < 2) {
                $bad[] = 'строка ' . ($n + 1) . ': нужны логин и пароль';
                continue;
            }
            [$login, $password] = $parts;
            $target = strtolower($parts[2] ?? '');
            if ($target === '') {
                $target = str_contains($login, '@') ? strtolower($login) : strtolower($login) . '@' . config('areas.default_domain');
                if (! Mailbox::query()->find($target)) {
                    $local = strstr($login, '@', true) ?: $login;
                    $target = strtolower($local) . '@' . config('areas.default_domain');
                }
            }
            if (! Mailbox::query()->find($target)) {
                $bad[] = 'строка ' . ($n + 1) . ': у нас нет ящика ' . $target . ' — сначала создайте его';
                continue;
            }
            $existing = MailMigration::query()->where('target', $target)->first();
            if ($existing && $existing->isBusy()) {
                $bad[] = $target . ': перенос уже идёт';
                continue;
            }
            $attrs = [
                'source_host' => $src['host'], 'source_port' => (int) ($src['port'] ?: 993), 'source_ssl' => (bool) ($src['ssl'] ?? true),
                'source_login' => $login, 'source_password' => $password, 'target' => $target,
                'status' => 'new', 'error' => null, 'created_by' => auth()->user()?->email,
            ];
            $existing ? $existing->update($attrs) : MailMigration::query()->create($attrs);
            $added++;
        }
        AdminAction::log('settings.update', 'перенос', 'добавлено ящиков: ' . $added);
        $msg = 'Добавлено ящиков: ' . $added;
        if ($bad) {
            return back()->with($added ? 'success' : 'error', $msg . '. Пропущено: ' . implode('; ', array_slice($bad, 0, 5)) . (count($bad) > 5 ? '…' : ''));
        }

        return back()->with('success', $msg);
    }

    public function test(MailMigration $migration): JsonResponse
    {
        if (! Imapsync::available()) {
            return response()->json(['ok' => false, 'message' => 'imapsync не установлен на сервере'], 422);
        }
        $r = $this->imapsync->test($migration);
        if (! $r['ok']) {
            $migration->update(['status' => 'failed', 'error' => $r['message']]);
        } elseif ($migration->status === 'failed') {
            $migration->update(['status' => 'new', 'error' => null]);
        }

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    public function run(Request $request, MailMigration $migration): RedirectResponse
    {
        $what = $request->validate(['what' => ['nullable', 'in:mail,dav,all']])['what'] ?? 'all';
        if ($migration->isBusy()) {
            return back()->with('error', 'Перенос уже идёт');
        }
        if (in_array($what, ['mail', 'all'], true) && ! Imapsync::available()) {
            return back()->with('error', 'imapsync не установлен на сервере');
        }
        $this->imapsync->start($migration, $what);
        AdminAction::log('settings.update', 'перенос', $migration->source_login . ' → ' . $migration->target . ' (' . $what . ')');

        return back()->with('success', 'Перенос запущен: ' . $migration->target);
    }

    /** Запустить все незавершённые по очереди — команда сама берёт следующую, когда закончит текущую. */
    public function runAll(Request $request): RedirectResponse
    {
        $what = $request->validate(['what' => ['nullable', 'in:mail,dav,all']])['what'] ?? 'all';
        $pending = MailMigration::query()->whereIn('status', ['new', 'failed', 'done'])->orderBy('id')->get();
        $n = 0;
        foreach ($pending as $m) {
            if ($m->isBusy()) {
                continue;
            }
            $m->update(['status' => 'queued', 'what' => $what, 'error' => null]);
            $n++;
        }
        if ($n && ! MailMigration::query()->where('status', 'running')->exists()) {
            $this->imapsync->startQueue();
        }
        AdminAction::log('settings.update', 'перенос', 'запущены все: ' . $n);

        return back()->with('success', 'В очередь поставлено ящиков: ' . $n);
    }

    public function log(MailMigration $migration): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($migration->log_path && is_file($migration->log_path), 404);
        $size = filesize($migration->log_path);
        $fh = fopen($migration->log_path, 'rb');
        fseek($fh, max(0, $size - 200000));
        $text = (string) stream_get_contents($fh);
        fclose($fh);

        return response($text, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function destroy(MailMigration $migration): RedirectResponse
    {
        if ($migration->isBusy()) {
            return back()->with('error', 'Дождитесь окончания переноса');
        }
        if ($migration->log_path) {
            @unlink($migration->log_path);
        }
        $migration->delete();

        return back()->with('success', 'Строка удалена');
    }
}
