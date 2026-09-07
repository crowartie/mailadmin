<?php

use App\Http\Controllers\AliasController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\RulesController;
use App\Http\Controllers\SecurityController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ── Админка: только на своём порту (config/areas.php) ────────────────
Route::middleware('area:admin')->group(function () {

    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store']);
    });
    Route::post('/logout', [LoginController::class, 'destroy']);

    // Второй шаг входа: пароль уже проверен, ждём код.
    Route::middleware('auth')->group(function () {
        Route::get('/login/code', [TwoFactorController::class, 'challenge']);
        Route::post('/login/code', [TwoFactorController::class, 'verify']);
    });

    Route::middleware(['auth', '2fa'])->group(function () {
        Route::get('/', DashboardController::class);

        Route::get('/domains', [DomainController::class, 'index']);

        Route::get('/mailboxes', [MailboxController::class, 'index']);
        Route::get('/mailboxes/create', [MailboxController::class, 'create']);
        Route::post('/mailboxes', [MailboxController::class, 'store']);
        // Адрес содержит @ и точки, поэтому параметр берём как есть, без ограничений маршрута.
        Route::get('/mailboxes/{mailbox}/edit', [MailboxController::class, 'edit'])->where('mailbox', '.*');
        Route::put('/mailboxes/{mailbox}', [MailboxController::class, 'update'])->where('mailbox', '.*');
        Route::delete('/mailboxes/{mailbox}', [MailboxController::class, 'destroy'])->where('mailbox', '.*');

        Route::get('/rules', [RulesController::class, 'index']);

        Route::get('/aliases', [AliasController::class, 'index']);
        Route::get('/aliases/create', [AliasController::class, 'create']);
        Route::post('/aliases', [AliasController::class, 'store']);
        Route::get('/aliases/{alias}/edit', [AliasController::class, 'edit'])->where('alias', '.*');
        Route::put('/aliases/{alias}', [AliasController::class, 'update'])->where('alias', '.*');
        Route::delete('/aliases/{alias}', [AliasController::class, 'destroy'])->where('alias', '.*');

        Route::get('/security', [SecurityController::class, 'index']);
        Route::get('/security/2fa', [TwoFactorController::class, 'setup']);
        Route::post('/security/2fa', [TwoFactorController::class, 'enable']);
        Route::delete('/security/2fa', [TwoFactorController::class, 'disable']);

        // Разделы из плана, до которых ещё не дошли: честная заглушка вместо 404.
        $planned = [
            'units' => ['Подразделения', 'Дерево отделов и группы для прав и рассылок. Появится вместе с импортом сотрудников из CSV.'],
            'maillists' => ['Рассылки', 'Списки рассылки mlmmj: подписчики, модераторы, архив.'],
            'queue' => ['Очередь', 'Письма, ожидающие отправки: повторить, удалить, посмотреть заголовки. Требует агента на почтовом сервере.'],
            'logs' => ['Журналы', 'Почта, спам, безопасность, ошибки, действия администраторов — с поиском по адресу и message-id.'],
            'settings' => ['Настройки', 'Домены и DKIM, антиспам, вложения, архив и бэкап, сертификаты, администраторы.'],
        ];
        foreach ($planned as $path => [$title, $note]) {
            Route::get("/{$path}", fn () => Inertia::render('Placeholder', ['title' => $title, 'note' => $note]));
        }
    });
});
