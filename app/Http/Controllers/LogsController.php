<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Services\Server\MailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Журналы: лента событий (почта, спам, входы, ошибки, действия админов) и «путь письма». */
class LogsController extends Controller
{
    public function __construct(private readonly MailLog $log)
    {
    }

    public function index(Request $request): Response
    {
        $type = (string) $request->query('type', 'all');
        $q = (string) $request->query('q', '');
        $period = (string) $request->query('period', 'day');
        $trace = (string) $request->query('trace', '');

        return Inertia::render('Logs/Index', [
            'events' => $this->events($type, $q, $period),
            'path' => $trace !== '' ? $this->log->path($trace) : null,
            'filters' => ['type' => $type, 'q' => $q, 'period' => $period, 'trace' => $trace],
            'readable' => $this->log->readable(),
        ]);
    }

    /** Живая лента: события новее переданной метки времени. */
    public function tail(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', 'all');
        $q = (string) $request->query('q', '');
        $after = (string) $request->query('after', '');

        return response()->json($this->events($type, $q, 'day', $after ?: null, 100));
    }

    public function path(Request $request): JsonResponse
    {
        $needle = trim((string) $request->query('id', ''));
        abort_if($needle === '' || mb_strlen($needle) > 300, 422);

        return response()->json($this->log->path($needle));
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->events((string) $request->query('type', 'all'), (string) $request->query('q', ''), (string) $request->query('period', 'day'), null, 5000);
        AdminAction::log('logs.export', null, count($rows) . ' строк');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Время', 'Тип', 'Кто · кому', 'Что', 'Queue-ID', 'Message-ID'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r['time'], $r['kind'], $r['who'], $r['what'], $r['qid'] ?? '', $r['msgid'] ?? ''], ';');
            }
            fclose($out);
        }, 'mail-log-' . date('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /** @return array<int,array<string,mixed>> */
    private function events(string $type, string $q, string $period, ?string $after = null, int $limit = 300): array
    {
        $since = $after ?: match ($period) {
            'hour' => date('Y-m-d\TH:i', time() - 3600),
            'week' => null,
            default => date('Y-m-d\TH', time() - 86400),
        };
        $events = [];
        if ($type === 'all' || $type !== 'admin') {
            $events = $this->log->events($type === 'admin' ? 'none' : $type, $q, $limit, $since);
        }
        if ($type === 'all' || $type === 'admin') {
            $admin = AdminAction::query()
                ->when($since, fn ($qq) => $qq->where('created_at', '>=', str_replace('T', ' ', $since)))
                ->when($q !== '', fn ($qq) => $qq->where(fn ($w) => $w->where('actor', 'like', "%{$q}%")->orWhere('target', 'like', "%{$q}%")->orWhere('details', 'like', "%{$q}%")->orWhere('action', 'like', "%{$q}%")))
                ->orderByDesc('id')->limit($limit)->get()
                ->map(fn (AdminAction $a) => [
                    'time' => $a->created_at->format('Y-m-d\TH:i:s'), 'ts' => $a->created_at->format('H:i:s'), 'kind' => 'admin',
                    'who' => $a->actor . ($a->ip ? ' · ' . $a->ip : ''), 'what' => self::describe($a), 'qid' => null, 'msgid' => null,
                ])->all();
            $events = array_merge($events, $admin);
            usort($events, fn ($a, $b) => strcmp($b['time'], $a['time']));
            $events = array_slice($events, 0, $limit);
        }

        return array_values($events);
    }

    public static function describe(AdminAction $a): string
    {
        $t = $a->target ? ' ' . $a->target : '';
        $d = $a->details ? ' — ' . $a->details : '';
        $map = [
            'mailbox.create' => 'создал ящик', 'mailbox.update' => 'изменил ящик', 'mailbox.delete' => 'удалил ящик',
            'queue.retry' => 'повторил отправку', 'queue.delete' => 'удалил из очереди', 'queue.hold' => 'поставил на удержание', 'queue.release' => 'снял с удержания', 'queue.flush' => 'отправил всю очередь', 'queue.download' => 'скачал письмо из очереди',
            'logs.export' => 'выгрузил журнал', 'security.unban' => 'разблокировал', 'security.ban' => 'заблокировал', 'security.kick' => 'завершил сеанс',
            'settings.update' => 'изменил настройки', 'dkim.rotate' => 'сменил ключ DKIM', 'cert.renew' => 'продлил сертификат', 'backup.run' => 'запустил копию', 'backup.restore' => 'восстановил ящик',
            'quarantine.release' => 'выпустил из карантина', 'quarantine.delete' => 'удалил из карантина', 'wblist.add' => 'добавил в список', 'wblist.delete' => 'убрал из списка',
            'unit.create' => 'создал подразделение', 'unit.update' => 'изменил подразделение', 'unit.delete' => 'удалил подразделение', 'unit.move' => 'перенёс сотрудника',
            'list.create' => 'создал рассылку', 'list.update' => 'изменил рассылку', 'list.delete' => 'удалил рассылку', 'list.approve' => 'пропустил письмо в рассылку', 'list.reject' => 'отклонил письмо в рассылку',
            'admin.create' => 'назначил администратора', 'admin.update' => 'изменил администратора', 'admin.delete' => 'снял администратора',
            'employee.impersonate' => 'вошёл как сотрудник', 'employee.reset' => 'отправил ссылку сброса пароля', 'employee.import' => 'импортировал сотрудников', 'employee.devices' => 'отключил устройства',
            'contact.approve' => 'одобрил контакт',
        ];

        return ($map[$a->action] ?? $a->action) . $t . $d;
    }
}
