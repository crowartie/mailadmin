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
        // За обратным прокси (TRUSTED_PROXIES=ip,ip; за Octane это 127.0.0.1) адрес клиента и порт берём из
        // X-Forwarded-* — иначе журнал входов и fail2ban видят адрес прокси, а админка и веб-почта путают порты.
        // Список читает наш TrustProxies из config (env() здесь пуст при закэшированной конфигурации).
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustProxies::class);
        // DAV-клиенты (телефон, Outlook) токенов CSRF не знают — авторизация там своя, Basic.
        // mail/api/activity — маячок действий: уходит sendBeacon без заголовков, пишет только строку
        // журнала для уже вошедшего человека и ограничен по частоте.
        $middleware->validateCsrfTokens(except: ['dav', 'dav/*', 'autodiscover/*', 'Autodiscover/*', '.well-known/*', 'mail/api/activity']);
        // Зону проверяем раньше всего: Laravel сам двигает «auth» в начало цепочки,
        // и без этого запрос админского адреса через порт веб-почты заводил сессию и уводил
        // на страницу входа вместо честного «такого адреса здесь нет».
        $middleware->prependToPriorityList(
            \Illuminate\Session\Middleware\StartSession::class,
            EnsureArea::class,
        );
        $middleware->alias([
            'area' => EnsureArea::class,
            '2fa' => EnsureTwoFactorVerified::class,
            'mail.auth' => EnsureMailSession::class,
            'mail.activity' => \App\Http\Middleware\RecordActivity::class,
            'role' => EnforceRole::class,
        ]);
        // Неавторизованных ведём на вход своей зоны.
        $middleware->redirectGuestsTo(fn (Request $request) => Area::isAdmin($request) ? '/login' : '/mail/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 397: до этого «нет такой записи» и «нет такого адреса» приходили в интерфейс
        // английскими текстами фреймворка — «No query results for model …» и пустым
        // «Not Found». Отвечаем словами и тем же способом, что и остальные ошибки почты.
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, Request $request) {
            $msg = 'Не найдено: запись уже удалена или относится к другому ящику';

            return $request->expectsJson() || $request->is('mail/api/*') || $request->is('api/*')
                ? response()->json(['message' => $msg], 404)
                : back()->with('error', $msg);
        });
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if (! $request->is('mail/api/*') && ! $request->is('api/*')) {
                return null;   // обычные страницы показывают свою страницу «такой страницы нет»
            }

            return response()->json(['message' => 'Такого адреса нет — обновите страницу'], 404);
        });
        // Всё остальное, что приходит от php-imap, — это разговор с почтовым сервером,
        // а не поломка приложения. Раньше такое доезжало до человека как «Server Error»:
        // так отвечал, например, запрос письма с номером ноль (такого номера в IMAP нет,
        // Dovecot отвечает пустотой). Пишем словами, подробности оставляем в журнале.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! str_starts_with($e::class, 'Webklex\\PHPIMAP\\Exceptions\\')) {
                return null;
            }
            \Illuminate\Support\Facades\Log::warning('почтовый сервер: ' . $e::class . ': ' . $e->getMessage());
            $msg = 'Почтовый сервер ответил не так, как мы ожидали. Обновите страницу; если повторится — сообщите администратору.';

            return $request->expectsJson() || $request->is('mail/api/*') || $request->is('api/*')
                ? response()->json(['message' => $msg], 502)
                : back()->with('error', $msg);
        });
        // Ошибки работы с почтой приходят своим типом и уже с человеческим текстом:
        // хранилищу больше не нужно знать про HTTP, чтобы сообщить о нехватке прав.
        $exceptions->render(function (\App\Exceptions\MailException $e, Request $request) {
            return $request->expectsJson() || $request->is('mail/api/*') || $request->is('api/*')
                ? response()->json(['message' => $e->getMessage()], $e->status())
                : back()->with('error', $e->getMessage());
        });
        // Dovecot отказал по ACL (чужая папка только для чтения) — это не ошибка сервера.
        // Ловим именно почтовые исключения: раньше проверялся текст любого исключения,
        // и недоступный служебный каталог показывал сотруднику «владелец открыл эту папку
        // только для просмотра».
        $exceptions->render(function (\Throwable $e, Request $request) {
            // У php-imap все исключения наследуют \Exception напрямую, общего предка нет —
            // поэтому отличаем их по пространству имён.
            if (! str_starts_with($e::class, 'Webklex\\PHPIMAP\\Exceptions\\')) {
                return null;
            }
            if (! str_contains($e->getMessage(), 'NOPERM') && ! str_contains($e->getMessage(), 'Permission denied')) {
                return null;
            }
            $msg = 'Нет прав: владелец открыл эту папку только для просмотра';

            return $request->expectsJson() || $request->is('mail/api/*') ? response()->json(['message' => $msg], 403) : back()->with('error', $msg);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
