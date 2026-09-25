<?php

namespace App\Http\Middleware;

use App\Models\MailSession;
use App\Services\Mail\ActivityMap;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Журнал действий сотрудника в веб-почте.
 *
 * Стоит после mail.auth на всех адресах почты: записывает действие уже после ответа,
 * одной строкой в webmail_activity, и никогда не ломает сам запрос — если запись
 * не удалась, ответ всё равно уходит человеку. Что именно считается действием
 * и что попадает в подробности, решает ActivityMap.
 */
class RecordActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $t0 = microtime(true);
        $response = $next($request);
        try {
            $this->record($request, $response, (int) round((microtime(true) - $t0) * 1000));
        } catch (\Throwable $e) {
            Log::debug('журнал действий: ' . $e->getMessage());
        }

        return $response;
    }

    private function record(Request $request, Response $response, int $ms): void
    {
        $user = (string) $request->session()->get('mail.user', '');
        if ($user === '') {
            return;
        }
        $status = $response->getStatusCode();
        $input = $request->isMethod('GET') ? [] : $request->input();
        // Приложение ходит в тот же API под /api/v1 — для журнала это те же действия, что /mail/api.
        $path = preg_replace('#^api/v1/#', 'mail/api/', $request->path());
        $desc = ActivityMap::describe($request->method(), $path, $request->query(), $input, count($request->allFiles()));
        if ($desc === null && $status < 500) {
            return;
        }
        [$action, $folder, $detail] = $desc ?? ['error', null, mb_substr($request->method() . ' ' . $request->path(), 0, 120)];
        $error = null;
        if ($status >= 500) {
            $e = $response->exception ?? null;
            $error = mb_substr($e instanceof \Throwable ? (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage() : 'ответ ' . $status, 0, 160);
        }
        DB::table('webmail_activity')->insert([
            'user' => mb_strtolower($user),
            'at' => now(),
            'action' => $action,
            'folder' => $folder,
            'detail' => $detail !== null ? mb_substr($detail, 0, 120) : null,
            'ms' => max(0, $ms),
            'status' => $status,
            'client' => mb_substr(MailSession::device($request->userAgent()), 0, 40),
            'source' => 'api',
            'master' => (bool) $request->session()->get('mail.master', false),
            'error' => $error,
        ]);
    }
}
