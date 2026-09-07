<?php

use App\Http\Controllers\Mail\Api\ActionController;
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
        });

        // Календарь и контакты — один интерфейс с почтой; движок (sabre/dav) — следующий шаг.
        Route::get('/calendar', fn () => Inertia::render('Mail/Soon', ['section' => 'calendar']));
        Route::get('/contacts', fn () => Inertia::render('Mail/Soon', ['section' => 'contacts']));
    });
});
