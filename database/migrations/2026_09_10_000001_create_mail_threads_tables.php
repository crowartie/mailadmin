<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индекс цепочек ответов: одна строка на письмо с номером цепочки, чтобы «вся переписка» находилась
 * одним запросом по ключу, а не поиском по заголовкам всей папки (на 13 тыс. писем — 0,3 с на каждый показ).
 * mail_thread_refs — обратные ссылки (кто на кого ссылается), чтобы цепочка собиралась правильно
 * независимо от порядка индексации папок. mail_thread_state — докуда папка проиндексирована (UIDNEXT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_threads', function (Blueprint $table) {
            $table->id();
            $table->string('user', 200);
            $table->string('folder', 255);
            $table->unsignedInteger('uid');
            $table->string('message_id', 255)->default('');
            $table->string('thread_id', 255);
            $table->timestamp('date')->nullable();
            $table->unique(['user', 'folder', 'uid']);
            $table->index(['user', 'message_id']);
            $table->index(['user', 'thread_id']);
        });
        Schema::create('mail_thread_refs', function (Blueprint $table) {
            $table->id();
            $table->string('user', 200);
            $table->string('ref_id', 255);      // Message-ID, на который ссылается письмо (In-Reply-To / References)
            $table->string('thread_id', 255);   // цепочка ссылающегося письма
            $table->index(['user', 'ref_id']);
            $table->index(['user', 'thread_id']);
        });
        Schema::create('mail_thread_state', function (Blueprint $table) {
            $table->id();
            $table->string('user', 200);
            $table->string('folder', 255);
            $table->unsignedBigInteger('uidvalidity')->default(0);
            $table->unsignedInteger('uidnext')->default(1);
            $table->timestamp('updated_at')->nullable();
            $table->unique(['user', 'folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_thread_state');
        Schema::dropIfExists('mail_thread_refs');
        Schema::dropIfExists('mail_threads');
    }
};
