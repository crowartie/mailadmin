<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Кто ответил» в общих папках (обращение №57): кто, когда и каким письмом ответил на письмо
 * из чужого ящика. Сам ответ лежит файлом .eml рядом с приложением (SharedReplies::dir()),
 * потому что в общей папке его нет — он в «Отправленных» ответившего.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_shared_replies', function (Blueprint $t) {
            $t->id();
            $t->string('owner', 190);                     // чей ящик (info@…)
            $t->string('folder', 200);                    // папка в ящике владельца (INBOX, Закупки)
            $t->string('message_id', 190);                // Message-ID исходного письма без <>
            $t->string('user', 190);                      // кто ответил
            $t->string('reply_message_id', 190)->nullable();
            $t->string('subject', 500)->nullable();
            $t->string('file', 255);                      // путь к .eml относительно SharedReplies::dir()
            $t->unsignedBigInteger('size')->default(0);
            $t->timestamp('replied_at')->nullable();
            $t->index(['owner', 'message_id']);
            $t->index('replied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_shared_replies');
    }
};
