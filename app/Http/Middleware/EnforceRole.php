<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Роли администраторов. «Только просмотр» — ничего не меняет; «Оператор приёмной» —
 * только ящики, псевдонимы и контакты компании; остальное — полные права.
 */
class EnforceRole
{
    private const OPERATOR_PATHS = ['mailboxes', 'mailboxes/*', 'aliases', 'aliases/*', 'company-contacts', 'company-contacts/*', 'logout', 'security/2fa'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $request->isMethodSafe()) {
            return $next($request);
        }
        $role = $user->role ?? 'admin';
        $denied = match ($role) {
            'viewer' => ! $request->is('logout', 'security/2fa'),
            'operator' => ! $request->is(...self::OPERATOR_PATHS),
            default => false,
        };
        if ($denied) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Недостаточно прав'], 403);
            }

            return back()->with('error', 'У вашей роли («' . $user->roleTitle() . '») нет прав на это действие');
        }

        return $next($request);
    }
}
