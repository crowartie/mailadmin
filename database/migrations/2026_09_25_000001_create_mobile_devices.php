<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Устройства мобильного приложения: вход по токену, адрес для push (см. MobileDevices, docs/mobile-api.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $t) {
            $t->id();
            $t->string('user', 190)->index();
            $t->char('token_hash', 64)->unique();        // SHA-256 от токена; сам токен есть только у телефона
            $t->string('name', 120)->default('');        // «Pixel 9, Android 15»
            $t->string('platform', 20)->default('');     // android | ios | desktop
            $t->string('app_version', 20)->default('');
            $t->string('push_kind', 16)->nullable();     // fcm | rustore | apns
            $t->string('push_token', 512)->nullable();
            $t->boolean('push_shared')->default(false);  // уведомления и из общих папок
            $t->string('ip', 64)->default('');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('last_seen_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_devices');
    }
};
