<?php

namespace App\Http\Controllers;

use App\Services\Mail\ActivityMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Активность»: чем сотрудники пользуются в веб-почте, сколько ждут, где спотыкаются —
 * и как это изменилось по сравнению с прошлым таким же периодом. Просмотры ящиков
 * администратором под мастер-паролем по умолчанию не считаются.
 */
class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $period = in_array($request->query('period'), ['day', 'week', 'month'], true) ? $request->query('period') : 'day';
        $user = mb_strtolower(trim((string) $request->query('user', '')));
        $master = (bool) $request->query('master', false);
        $hours = match ($period) { 'week' => 24 * 7, 'month' => 24 * 30, default => 24 };
        $from = now()->subHours($hours);
        $prevFrom = now()->subHours(2 * $hours);

        $base = fn ($start, $end = null) => DB::table('webmail_activity')
            ->where('at', '>=', $start)
            ->when($end, fn ($q) => $q->where('at', '<', $end))
            ->when(! $master, fn ($q) => $q->where('master', false))
            ->when($user !== '', fn ($q) => $q->where('user', $user));

        $tile = fn ($q) => [
            'users' => (int) (clone $q)->distinct()->count('user'),
            'actions' => (int) (clone $q)->count(),
            'errors' => (int) (clone $q)->where('status', '>=', 500)->count(),
            'ms' => (int) round((float) ((clone $q)->where('source', 'api')->whereIn('action', ['list', 'search', 'open', 'thread', 'send', 'page.folder'])->avg('ms') ?? 0)),
            'sent' => (int) (clone $q)->where('action', 'send')->count(),
        ];
        $now = $tile($base($from));
        $prev = $tile($base($prevFrom, $from));

        $prevByAction = $base($prevFrom, $from)->select('action', DB::raw('count(*) n'))->groupBy('action')->pluck('n', 'action')->all();
        $byAction = $base($from)->select('action', DB::raw('count(*) n'), DB::raw('round(avg(ms)) ms'), DB::raw('max(ms) max_ms'), DB::raw('sum(status >= 500) errors'), DB::raw('count(distinct user) users'))
            ->groupBy('action')->orderByDesc('n')->get()
            ->map(fn ($r) => ['action' => $r->action, 'label' => ActivityMap::LABELS[$r->action] ?? $r->action, 'n' => (int) $r->n, 'prev' => (int) ($prevByAction[$r->action] ?? 0), 'ms' => (int) $r->ms, 'max' => (int) $r->max_ms, 'errors' => (int) $r->errors, 'users' => (int) $r->users])
            ->all();

        $byUser = $base($from)->select('user', DB::raw('count(*) n'), DB::raw('max(at) last'), DB::raw("sum(action = 'send') sent"), DB::raw("sum(action = 'open') opened"), DB::raw("sum(action = 'search') searched"), DB::raw('sum(status >= 500) errors'), DB::raw('round(avg(ms)) ms'), DB::raw('count(distinct client) clients'))
            ->groupBy('user')->orderByDesc('n')->limit(200)->get()
            ->map(fn ($r) => ['user' => $r->user, 'n' => (int) $r->n, 'last' => (string) $r->last, 'sent' => (int) $r->sent, 'opened' => (int) $r->opened, 'searched' => (int) $r->searched, 'errors' => (int) $r->errors, 'ms' => (int) $r->ms, 'clients' => (int) $r->clients])
            ->all();

        $byClient = $base($from)->select('client', DB::raw('count(*) n'), DB::raw('count(distinct user) users'))
            ->groupBy('client')->orderByDesc('n')->get()
            ->map(fn ($r) => ['client' => $r->client !== '' ? $r->client : 'неизвестно', 'n' => (int) $r->n, 'users' => (int) $r->users])->all();

        $byFolder = $base($from)->whereNotNull('folder')->whereIn('action', ['list', 'open', 'page.folder', 'search'])
            ->select('folder', DB::raw('count(*) n'))->groupBy('folder')->orderByDesc('n')->get()
            ->map(fn ($r) => ['folder' => $r->folder, 'n' => (int) $r->n])->all();

        $errors = $base($from)->where('status', '>=', 500)->orderByDesc('id')->limit(60)->get()
            ->map(fn ($r) => ['at' => (string) $r->at, 'user' => $r->user, 'action' => ActivityMap::LABELS[$r->action] ?? $r->action, 'status' => (int) $r->status, 'error' => $r->error, 'detail' => $r->detail, 'client' => $r->client])->all();

        $slow = $base($from)->where('source', 'api')->where('ms', '>=', 3000)->orderByDesc('ms')->limit(30)->get()
            ->map(fn ($r) => ['at' => (string) $r->at, 'user' => $r->user, 'action' => ActivityMap::LABELS[$r->action] ?? $r->action, 'ms' => (int) $r->ms, 'detail' => $r->detail, 'folder' => $r->folder])->all();

        // Ход по времени: по часам за сутки, по дням за неделю и месяц.
        $bucket = $period === 'day' ? "date_format(at, '%H:00')" : "date_format(at, '%d.%m')";
        $timeline = $base($from)->select(DB::raw("$bucket b"), DB::raw('count(*) n'), DB::raw('count(distinct user) users'))
            ->groupBy('b')->orderBy(DB::raw('min(at)'))->get()
            ->map(fn ($r) => ['b' => $r->b, 'n' => (int) $r->n, 'users' => (int) $r->users])->all();
        // Пустые часы и дни — тоже столбиками: раньше час без действий просто выпадал, и под
        // одинаковыми столбиками шло «04:00, 06:00» — будто 05:00 не существовало.
        $step = $period === 'day' ? 'hour' : 'day';
        $have = collect($timeline)->keyBy('b');
        $filled = [];
        for ($t = $from->copy()->add(1, $step)->startOf($step); $t <= now(); $t->add(1, $step)) {
            $k = $t->format($period === 'day' ? 'H:00' : 'd.m');
            $filled[] = $have[$k] ?? ['b' => $k, 'n' => 0, 'users' => 0];
        }
        $timeline = $filled;

        $users = DB::table('webmail_activity')->where('at', '>=', now()->subDays(30))->distinct()->orderBy('user')->pluck('user')->all();

        return Inertia::render('Activity/Index', [
            'filters' => ['period' => $period, 'user' => $user, 'master' => $master],
            'now' => $now, 'prev' => $prev,
            'byAction' => $byAction, 'byUser' => $byUser, 'byClient' => $byClient, 'byFolder' => $byFolder,
            'errors' => $errors, 'slow' => $slow, 'timeline' => $timeline, 'users' => $users,
            'oldest' => DB::table('webmail_activity')->min('at'),
        ]);
    }
}
