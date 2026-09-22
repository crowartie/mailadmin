<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал действий сотрудников в веб-почте. Здесь только тип действия и числа:
 * ни темы, ни адресов, ни текста поиска — по нему смотрят, чем люди пользуются,
 * где ждут и где спотыкаются, а не что они пишут.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_activity', function (Blueprint $t) {
            $t->id();
            $t->string('user', 190)->index();
            $t->timestamp('at')->useCurrent()->index();
            $t->string('action', 40)->index();          // open, send, msg.move, page.folder, compose.open …
            $t->string('folder', 16)->nullable();        // роль папки: inbox, sent, drafts, trash, junk, own …
            $t->string('detail', 120)->nullable();       // числа и признаки: «стр. 3», «адресатов 2, файлов 1»
            $t->unsignedInteger('ms')->default(0);       // сколько ждал ответа
            $t->unsignedSmallInteger('status')->default(200);
            $t->string('client', 40)->default('');       // «Windows · Chrome», «iPhone · Safari»
            $t->string('source', 6)->default('api');     // api — сервер видел запрос; ui — маячок из браузера
            $t->boolean('master')->default(false);       // администратор смотрел ящик под мастер-паролем
            $t->string('error', 160)->nullable();        // текст ошибки, если ответ был 5xx
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_activity');
    }
};
