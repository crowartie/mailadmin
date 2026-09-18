<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Письмо ушло, а копию в «Отправленные» положить не удалось: обрыв IMAP, занятый почтовый
// ящик, переполненная квота. Раньше такой сбой попадал только в журнал, и копия пропадала
// молча — человек видел «отправлено», а в папке ничего не было. Теперь письмо ждёт здесь,
// и mail:sent-retry доносит копию.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_sent_retry', function (Blueprint $table) {
            $table->id();
            $table->string('user')->index();
            $table->string('folder', 255);
            $table->string('message_id', 998)->nullable();
            $table->string('subject', 998)->nullable();
            $table->string('path', 255);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        // Отложенная отправка повторяется при временном сбое, а не падает с первого раза.
        Schema::table('webmail_outbox', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_sent_retry');
        Schema::table('webmail_outbox', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
