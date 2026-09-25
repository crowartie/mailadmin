<?php

use App\Http\Controllers\Mail\Api\MobileController;
use App\Http\Controllers\Mail\FeedbackController;
use Illuminate\Support\Facades\Route;

/*
 * Мобильное приложение (docs/mobile-api.md): вход по токену, те же данные, что у веб-почты.
 * Только на адресе веб-почты; без сессии и CSRF — авторизация заголовком Authorization: Bearer.
 */
Route::middleware('area:mail')->group(function () {
    Route::get('/.well-known/mailadmin', [MobileController::class, 'discover']);

    Route::prefix('/api/v1')->group(function () {
        Route::post('login', [MobileController::class, 'login'])->middleware('throttle:20,1');
        Route::post('login/code', [MobileController::class, 'code'])->middleware('throttle:20,1');

        Route::middleware(['mobile.token', 'mail.activity'])->group(function () {
            Route::get('me', [MobileController::class, 'me']);
            Route::delete('session', [MobileController::class, 'logout']);
            Route::post('devices/push', [MobileController::class, 'push']);
            Route::get('devices', [MobileController::class, 'devices']);
            Route::delete('devices/{id}', [MobileController::class, 'revokeDevice'])->whereNumber('id');

            // «Сообщить о проблеме» — в веб-почте эти адреса объявлены вне общей группы.
            Route::post('feedback', [FeedbackController::class, 'store']);
            Route::post('feedback/{ticket}/reply', [FeedbackController::class, 'reply'])->whereNumber('ticket');
            Route::get('feedback', [FeedbackController::class, 'listJson']);
            Route::get('feedback/unread', [FeedbackController::class, 'unread']);
            Route::get('feedback/{ticket}', [FeedbackController::class, 'poll'])->whereNumber('ticket');
            Route::get('feedback/{ticket}/file/{message}', [FeedbackController::class, 'file'])->whereNumber('ticket')->whereNumber('message');

            // Всё остальное — тот же API, что у веб-почты.
            require base_path('routes/mail-api.php');
        });
    });
});
