<?php

namespace App\Http\Middleware;

use App\Models\MailSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пользователь веб-почты — это не администратор: у него нет строки в users,
 * он входит своим почтовым паролем, и сессия хранит только факт входа.
 * Попутно отмечаем сеанс живым и не выпускаем из настроек, пока не включена
 * обязательная двухфакторная защита.
 */
class EnsureMailSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('mail.user')) {
            return redirect()->guest('/mail/login');
        }

        try {
            MailSession::seen($request, (string) $request->session()->get('mail.user'), (bool) $request->session()->get('mail.master'));
        } catch (\Throwable) {
            // таблицы ещё нет — не мешаем работать
        }

        if ($request->session()->get('mail.force2fa') && ! $request->is('mail/settings/security', 'mail/api/security*', 'mail/api/settings', 'mail/logout')) {
            return redirect('/mail/settings/security');
        }

        try {
            return $next($request);
        } catch (\Webklex\PHPIMAP\Exceptions\ConnectionFailedException|\Webklex\PHPIMAP\Exceptions\ImapServerErrorException $e) {
            // Пароль ящика сменил администратор (или вход закрыли) — сессия ещё жива, а IMAP уже не пускает.
            // Раньше это была ошибка 500 на каждый запрос; теперь — на страницу входа с объяснением.
            $chain = '';
            for ($x = $e; $x; $x = $x->getPrevious()) {
                $chain .= $x->getMessage() . ' ';
            }
            $auth = str_contains($chain, 'AUTHENTICATIONFAILED') || str_contains($chain, 'Authentication failed');
            if (! $auth) {
                // Сам почтовый сервер не отвечает — не разлогиниваем, просим подождать.
                abort(503, 'Почтовый сервер не отвечает — попробуйте через минуту');
            }
            foreach (['mail.user', 'mail.secret', 'mail.master', 'mail.force2fa', 'mail.pending'] as $key) {
                $request->session()->forget($key);
            }
            $message = 'Пароль ящика изменён или вход закрыт — войдите заново';
            // Фоновый опрос страницы получает 401 и сам уводит на вход — объяснение должно дожить до той страницы.
            $request->session()->flash('error', $message);
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 401);
            }

            return redirect('/mail/login');
        }
    }
}
