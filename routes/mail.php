<?php

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
        Route::get('/mail/folder/{folder}', [InboxController::class, 'index'])->where('folder', '.*');
        Route::get('/mail/message/{folder}/{uid}', [InboxController::class, 'show'])->where('folder', '.*')->whereNumber('uid');

        // Календарь и контакты — один интерфейс с почтой; движок подключим следующим шагом.
        Route::get('/calendar', fn () => Inertia::render('Mail/Soon', ['section' => 'calendar']));
        Route::get('/contacts', fn () => Inertia::render('Mail/Soon', ['section' => 'contacts']));
    });
});
