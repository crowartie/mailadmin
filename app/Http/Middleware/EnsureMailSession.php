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

        return $next($request);
    }
}
