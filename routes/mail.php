<?php

use App\Http\Controllers\Mail\Api\ActionController;
use App\Http\Controllers\Mail\Api\CalendarController;
use App\Http\Controllers\Mail\Api\ContactsController;
use App\Http\Controllers\Mail\DavController;
use App\Http\Controllers\Mail\GroupwareController;
use App\Http\Controllers\Mail\Api\ComposeController;
use App\Http\Controllers\Mail\Api\FolderController;
use App\Http\Controllers\Mail\Api\MessageController;
use App\Http\Controllers\Mail\Api\RulesController;
use App\Http\Controllers\Mail\Api\SecurityController as MailSecurityController;
use App\Http\Controllers\Mail\Api\SettingsController;
use App\Http\Controllers\Mail\Api\SuggestController;
use App\Http\Controllers\Mail\InboxController;
use App\Http\Controllers\Mail\LoginController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ── Веб-почта: пользовательская зона на своём порту ──────────────────
Route::middleware('area:mail')->group(function () {

    Route::get('/mail/login', [LoginController::class, 'create'])->name('mail.login');
    Route::post('/mail/login', [LoginController::class, 'store']);
    Route::get('/mail/login/code', [LoginController::class, 'code']);
    Route::post('/mail/login/code', [LoginController::class, 'verifyCode']);
    Route::post('/mail/logout', [LoginController::class, 'destroy']);
    // Выпуск письма из карантина по подписанной ссылке из сводки (вход не нужен).
    Route::get('/mail/quarantine/release/{id}/{secret}', [\App\Http\Controllers\Mail\QuarantineController::class, 'releaseSigned'])->name('mail.quarantine.release')->middleware('signed:relative');
    // Файл по ссылке из письма (https://files.<домен>/<токен>/<имя> → nginx переписывает в /f/…). Входа нет.
    // POST — ввод пароля ссылки на файл из облака.
    Route::match(['GET', 'HEAD', 'POST'], '/f/{token}/{name?}', [\App\Http\Controllers\Mail\FilesController::class, 'download'])
        ->where('token', '[A-Za-z0-9_-]{20,64}')->where('name', '.*')->middleware('throttle:120,1');

    // CalDAV/CardDAV для телефонов и почтовых программ (Basic-авторизация паролем от почты).
    Route::match(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'PROPFIND', 'PROPPATCH', 'REPORT', 'MKCOL', 'MKCALENDAR', 'MOVE', 'COPY', 'LOCK', 'UNLOCK', 'ACL'], '/dav/{path?}', DavController::class)->where('path', '.*');
    // Автонастройка почтовых программ (Outlook, Thunderbird, Android, iPhone) — без пароля, только адреса серверов.
    Route::match(['GET', 'POST'], '/autodiscover/autodiscover.xml', [\App\Http\Controllers\Mail\AutoconfigController::class, 'autodiscover']);
    Route::match(['GET', 'POST'], '/Autodiscover/Autodiscover.xml', [\App\Http\Controllers\Mail\AutoconfigController::class, 'autodiscover']);
    Route::get('/mail/config-v1.1.xml', [\App\Http\Controllers\Mail\AutoconfigController::class, 'autoconfig']);
    Route::get('/.well-known/autoconfig/mail/config-v1.1.xml', [\App\Http\Controllers\Mail\AutoconfigController::class, 'autoconfig']);
    Route::get('/mail/apple.mobileconfig', [\App\Http\Controllers\Mail\AutoconfigController::class, 'mobileconfig']);
    Route::get('/.well-known/mta-sts.txt', function () {
        $s = \App\Models\AppSetting::group('mtasts');
        abort_unless($s['enabled'] ?? false, 404);
        $mx = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'mail.' . config('areas.default_domain');

        return response("version: STSv1\nmode: {$s['mode']}\nmx: {$mx}\nmax_age: " . (int) ($s['max_age'] ?: 604800) . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    });
    Route::get('/mail/help', [\App\Http\Controllers\Mail\HelpController::class, 'index']);
    Route::get('/mail/setup', [\App\Http\Controllers\Mail\SetupController::class, 'index']);
    Route::get('/mail/server.crt', [\App\Http\Controllers\Mail\SetupController::class, 'certificate']);
    Route::post('/mail/setup/login', [\App\Http\Controllers\Mail\SetupController::class, 'login'])->middleware('throttle:12,1');
    // iPhone ищет календарь через PROPFIND /.well-known/caldav — GET-only маршрут отвечал 405, и учётка не проходила проверку.
    Route::match(['GET', 'HEAD', 'OPTIONS', 'PROPFIND'], '/.well-known/caldav', [DavController::class, 'wellKnown']);
    Route::match(['GET', 'HEAD', 'OPTIONS', 'PROPFIND'], '/.well-known/carddav', [DavController::class, 'wellKnown']);

    // Корень «/» здесь не объявляем: он есть у админки, а на почтовом порту его перенаправляет nginx.
    Route::middleware(['mail.auth', 'mail.activity'])->group(function () {
        Route::get('/mail', [InboxController::class, 'index']);
        Route::get('/mail/quarantine', [\App\Http\Controllers\Mail\QuarantineController::class, 'index']);
        // «Сообщить о проблеме»: форма и свои обращения — только для вошедшего сотрудника.
        Route::get('/mail/feedback', [\App\Http\Controllers\Mail\FeedbackController::class, 'index']);
        Route::post('/mail/api/feedback', [\App\Http\Controllers\Mail\FeedbackController::class, 'store']);
        Route::post('/mail/api/feedback/{ticket}/reply', [\App\Http\Controllers\Mail\FeedbackController::class, 'reply'])->whereNumber('ticket');
        Route::get('/mail/api/feedback', [\App\Http\Controllers\Mail\FeedbackController::class, 'listJson']);
        Route::get('/mail/api/feedback/unread', [\App\Http\Controllers\Mail\FeedbackController::class, 'unread']);
        Route::get('/mail/api/feedback/{ticket}', [\App\Http\Controllers\Mail\FeedbackController::class, 'poll'])->whereNumber('ticket');
        Route::get('/mail/feedback/{ticket}/file/{message}', [\App\Http\Controllers\Mail\FeedbackController::class, 'file'])->whereNumber('ticket')->whereNumber('message');
        Route::get('/mail/settings/{section?}', [InboxController::class, 'settings'])->where('section', '[a-z]+');
        Route::get('/mail/folder/{folder}', [InboxController::class, 'index'])->where('folder', '.*');
        // Печатная форма письма: отдельная страница, открывается в новой вкладке и сама вызывает печать.
        Route::get('/mail/print/{folder}/{uid}', [\App\Http\Controllers\Mail\PrintController::class, 'show'])->where('folder', '.*')->where('uid', '[1-9][0-9]*');

        // Живые данные для интерфейса.
        Route::prefix('/mail/api')->group(base_path('routes/mail-api.php'));

        // Календарь и контакты — тот же интерфейс, данные в встроенном sabre/dav.
        Route::get('/calendar', [GroupwareController::class, 'calendar']);
        Route::get('/contacts', [GroupwareController::class, 'contacts']);
        Route::get('/cloud', [GroupwareController::class, 'cloud']);
    });
});
