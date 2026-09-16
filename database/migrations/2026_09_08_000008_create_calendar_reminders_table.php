<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Отметки «напоминание по почте уже отправлено» (пользователь + событие + начало). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('user')->index();
            $table->string('key', 300);
            $table->timestamp('sent_at')->index();
            $table->unique(['user', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_reminders');
    }
};
