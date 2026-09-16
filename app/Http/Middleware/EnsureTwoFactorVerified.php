<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пускает дальше только сессию, прошедшую и пароль, и код.
 * Пользователь с включённым 2FA, но без проверенного кода, отправляется вводить код;
 * администратор без 2FA при включённой политике — подключать приложение.
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

        if ($user && ! $user->hasTwoFactor() && ! $request->is('security/2fa', 'logout') && (AppSetting::group('security')['admin_2fa'] ?? false)) {
            return redirect('/security/2fa')->with('error', 'По политике безопасности администратору нужна двухфакторная защита — подключите приложение.');
        }

        return $next($request);
    }
}
