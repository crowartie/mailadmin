<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пользователь веб-почты — это не администратор: у него нет строки в users,
 * он входит своим почтовым паролем, и сессия хранит только факт входа.
 */
class EnsureMailSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('mail.user')) {
            return redirect()->guest('/mail/login');
        }

        return $next($request);
    }
}
