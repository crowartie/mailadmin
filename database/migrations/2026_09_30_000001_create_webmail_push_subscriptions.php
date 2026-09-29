<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push-подписки браузеров и телефонов (веб-приложение на экране «Домой»): куда слать уведомление
 * о новом письме. Один сотрудник — несколько устройств. См. PushNotifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->string('user', 190);
            $t->char('endpoint_hash', 64)->unique();   // sha256 адреса: сам адрес длиннее, чем влезает в индекс
            $t->text('endpoint');
            $t->text('p256dh');
            $t->string('auth', 64);
            $t->string('agent', 200)->nullable();       // браузер/устройство — чтобы показать список в настройках
            $t->unsignedSmallInteger('failures')->default(0);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->index('user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_push_subscriptions');
    }
};
