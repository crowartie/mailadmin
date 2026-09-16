<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Данные веб-почты, которых нет в IMAP: настройки, метки, отложенные письма, напоминания,
// отправка по расписанию, недавние адресаты, правила. Ключ везде — адрес ящика.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_settings', function (Blueprint $table) {
            $table->string('user')->primary();
            $table->json('data');
            $table->timestamps();
        });

        Schema::create('webmail_labels', function (Blueprint $table) {
            $table->id();
            $table->string('user')->index();
            $table->string('name', 60);
            $table->string('color', 7)->default('#2F6FEB');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('webmail_snoozes', function (Blueprint $table) {
            $table->id();
            $table->string('user')->index();
            $table->string('message_id', 998);
            $table->string('origin', 255)->default('INBOX');
            $table->string('subject', 998)->nullable();
            $table->dateTime('until')->index();
            $table->timestamps();
        });

        Schema::create('webmail_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('user')->index();
            $table->string('message_id', 998);
            $table->string('subject', 998)->nullable();
            $table->string('to', 998)->nullable();
            $table->dateTime('remind_at')->index();
            $table->timestamps();
        });

        Schema::create('webmail_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('user')->index();
            $table->string('from');
            $table->json('recipients');
            $table->string('subject', 998)->nullable();
            $table->string('path');
            $table->dateTime('send_at')->index();
            $table->string('status', 20)->default('scheduled'); // scheduled | sent | failed
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('webmail_recents', function (Blueprint $table) {
            $table->id();
            $table->string('user');
            $table->string('email');
            $table->string('name')->nullable();
            $table->unsignedInteger('uses')->default(1);
            $table->dateTime('last_at');
            $table->unique(['user', 'email']);
        });

        Schema::create('webmail_rules', function (Blueprint $table) {
            $table->string('user')->primary();
            $table->json('rules');
            $table->json('autoreply')->nullable();
            $table->text('script')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['webmail_rules', 'webmail_recents', 'webmail_outbox', 'webmail_reminders', 'webmail_snoozes', 'webmail_labels', 'webmail_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
