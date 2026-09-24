<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Запомненные устройства веб-почты («Не выходить на этом устройстве»), см. RememberDevice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_remember', function (Blueprint $t) {
            $t->id();
            $t->string('user', 190)->index();
            $t->char('selector', 24)->unique();
            $t->char('token_hash', 64);
            $t->text('secret');                          // пароль ящика, зашифрованный ключом приложения
            $t->string('device', 120)->default('');
            $t->string('ip', 64)->default('');
            $t->string('session_id', 191)->nullable()->index();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_remember');
    }
};
