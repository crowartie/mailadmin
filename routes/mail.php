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
    Route::post('/mail/logout', [LoginController::class, 'destroy']);

    // CalDAV/CardDAV для телефонов и почтовых программ (Basic-авторизация паролем от почты).
    Route::match(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'PROPFIND', 'PROPPATCH', 'REPORT', 'MKCOL', 'MKCALENDAR', 'MOVE', 'COPY', 'LOCK', 'UNLOCK', 'ACL'], '/dav/{path?}', DavController::class)->where('path', '.*');
    Route::get('/.well-known/caldav', [DavController::class, 'wellKnown']);
    Route::get('/.well-known/carddav', [DavController::class, 'wellKnown']);

    // Корень «/» здесь не объявляем: он есть у админки, а на почтовом порту его перенаправляет nginx.
    Route::middleware('mail.auth')->group(function () {
        Route::get('/mail', [InboxController::class, 'index']);
        Route::get('/mail/settings/{section?}', [InboxController::class, 'settings'])->where('section', '[a-z]+');
        Route::get('/mail/folder/{folder}', [InboxController::class, 'index'])->where('folder', '.*');

        // Живые данные для интерфейса.
        Route::prefix('/mail/api')->group(function () {
            Route::get('folders', [FolderController::class, 'index']);
            Route::post('folders', [FolderController::class, 'store']);
            Route::patch('folders/{folder}', [FolderController::class, 'update'])->where('folder', '.*');
            Route::delete('folders/{folder}', [FolderController::class, 'destroy'])->where('folder', '.*');
            Route::post('folders/{folder}/empty', [FolderController::class, 'empty'])->where('folder', '.*');

            Route::get('list/{folder}', [MessageController::class, 'list'])->where('folder', '.*');
            Route::get('message/{folder}/{uid}/attachment/{index}', [MessageController::class, 'attachment'])->where('folder', '.*')->whereNumber('uid')->whereNumber('index');
            Route::get('message/{folder}/{uid}/raw', [MessageController::class, 'raw'])->where('folder', '.*')->whereNumber('uid');
            Route::get('message/{folder}/{uid}', [MessageController::class, 'show'])->where('folder', '.*')->whereNumber('uid');

            Route::post('action', [ActionController::class, 'store']);

            Route::post('send', [ComposeController::class, 'send']);
            Route::post('draft', [ComposeController::class, 'draft']);
            Route::get('draft/{uid}', [ComposeController::class, 'openDraft'])->whereNumber('uid');
            Route::get('outbox', [ComposeController::class, 'outbox']);
            Route::delete('outbox/{id}', [ComposeController::class, 'cancel'])->whereNumber('id');

            Route::get('suggest', SuggestController::class);

            Route::get('settings', [SettingsController::class, 'show']);
            Route::put('settings', [SettingsController::class, 'update']);
            Route::put('password', [SettingsController::class, 'password']);
            Route::get('labels', [SettingsController::class, 'labels']);
            Route::post('labels', [SettingsController::class, 'storeLabel']);
            Route::patch('labels/{id}', [SettingsController::class, 'updateLabel'])->whereNumber('id');
            Route::delete('labels/{id}', [SettingsController::class, 'destroyLabel'])->whereNumber('id');

            Route::get('rules', [RulesController::class, 'show']);
            Route::put('rules', [RulesController::class, 'update']);

            // Контакты.
            Route::get('contacts/books', [ContactsController::class, 'books']);
            Route::get('contacts/groups', [ContactsController::class, 'groups']);
            Route::get('contacts/export', [ContactsController::class, 'export']);
            Route::post('contacts/import', [ContactsController::class, 'import']);
            Route::get('contacts', [ContactsController::class, 'index']);
            Route::post('contacts', [ContactsController::class, 'store']);
            Route::get('contacts/{book}/{uri}', [ContactsController::class, 'show']);
            Route::put('contacts/{book}/{uri}', [ContactsController::class, 'update']);
            Route::delete('contacts/{book}/{uri}', [ContactsController::class, 'destroy']);
            Route::post('contacts/{book}/{uri}/copy', [ContactsController::class, 'copy']);
            Route::post('contacts/{book}/{uri}/suggest', [ContactsController::class, 'suggest']);

            // Календарь.
            Route::get('calendars', [CalendarController::class, 'calendars']);
            Route::post('calendars', [CalendarController::class, 'storeCalendar']);
            Route::patch('calendars/{calendar}', [CalendarController::class, 'updateCalendar']);
            Route::delete('calendars/{calendar}', [CalendarController::class, 'destroyCalendar']);
            Route::get('calendars/{calendar}/shares', [CalendarController::class, 'shares']);
            Route::post('calendars/{calendar}/shares', [CalendarController::class, 'share']);
            Route::delete('calendars/{calendar}/shares', [CalendarController::class, 'unshare']);
            Route::get('events', [CalendarController::class, 'events']);
            Route::post('events', [CalendarController::class, 'store']);
            Route::get('freebusy', [CalendarController::class, 'freebusy']);
            Route::get('events/{calendar}/{uri}', [CalendarController::class, 'show']);
            Route::put('events/{calendar}/{uri}', [CalendarController::class, 'update']);
            Route::delete('events/{calendar}/{uri}', [CalendarController::class, 'destroy']);
            Route::post('events/{calendar}/{uri}/respond', [CalendarController::class, 'respond']);
        });

        // Календарь и контакты — тот же интерфейс, данные в встроенном sabre/dav.
        Route::get('/calendar', [GroupwareController::class, 'calendar']);
        Route::get('/contacts', [GroupwareController::class, 'contacts']);
    });
});
