<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пускает дальше только сессию, прошедшую и пароль, и код.
 * Пользователь с включённым 2FA, но без проверенного кода, отправляется вводить код.
 */
class EnsureTwoFactorVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasTwoFactor() && ! $request->session()->get('2fa.verified')) {
            $request->session()->put('2fa.pending', true);

            return redirect('/login/code');
        }

        return $next($request);
    }
}
