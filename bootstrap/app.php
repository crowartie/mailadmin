<?php

use App\Http\Middleware\EnsureArea;
use App\Http\Middleware\EnsureMailSession;
use App\Http\Middleware\EnforceRole;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Area;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Веб-почта — вторая зона того же приложения, на своём порту.
            \Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/mail.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        // DAV-клиенты (телефон, Outlook) токенов CSRF не знают — авторизация там своя, Basic.
        $middleware->validateCsrfTokens(except: ['dav', 'dav/*', 'autodiscover/*', 'Autodiscover/*']);
        $middleware->alias([
            'area' => EnsureArea::class,
            '2fa' => EnsureTwoFactorVerified::class,
            'mail.auth' => EnsureMailSession::class,
            'role' => EnforceRole::class,
        ]);
        // Неавторизованных ведём на вход своей зоны.
        $middleware->redirectGuestsTo(fn (Request $request) => Area::isAdmin($request) ? '/login' : '/mail/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Dovecot отказал по ACL (чужая папка только для чтения) — это не ошибка сервера.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (str_contains($e->getMessage(), 'NOPERM') || str_contains($e->getMessage(), 'Permission denied')) {
                $msg = 'Нет прав: владелец открыл эту папку только для просмотра';

                return $request->expectsJson() || $request->is('mail/api/*') ? response()->json(['message' => $msg], 403) : back()->with('error', $msg);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
