<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Обращения сотрудников: «что-то не работает», предложения, вопросы.
 *
 * Как в трекерах (Jira, GitHub, Linear) состояние и итог — разные поля: status говорит, где обращение
 * в работе, resolution — чем закончилось. Поэтому «исправлено», «не ошибка» и «не будем исправлять»
 * попадают в один столбец resolution при status = closed, а не плодят статусы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('user', 200);                      // адрес сотрудника
            $table->string('user_name', 200)->default('');
            $table->string('kind', 12)->default('bug');       // bug | idea | question
            $table->string('subject', 300)->default('');
            $table->string('status', 12)->default('new');     // new | open | waiting | closed
            $table->string('resolution', 12)->nullable();     // done | not_a_bug | wont_fix | duplicate
            $table->string('priority', 10)->default('normal');// low | normal | high
            $table->unsignedBigInteger('duplicate_of')->nullable();

            // Что автоматически сняли в момент обращения — чтобы сотруднику не пришлось это описывать.
            $table->string('area', 10)->default('mail');      // mail | admin
            $table->string('page_url', 500)->default('');
            $table->string('page_title', 200)->default('');
            $table->string('client', 200)->default('');       // браузер и система, по-человечески
            $table->string('agent', 400)->default('');
            $table->string('ip', 45)->default('');
            $table->string('app_version', 40)->default('');
            $table->json('context')->nullable();              // экран, папка, последние ошибки страницы

            $table->string('assigned_to', 200)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by', 200)->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->boolean('new_for_admin')->default(true);  // есть непрочитанное администратором
            $table->boolean('new_for_user')->default(false);  // есть непрочитанное сотрудником
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user', 'created_at']);
        });

        Schema::create('feedback_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('feedback_tickets')->cascadeOnDelete();
            $table->string('author', 200);
            $table->string('author_role', 10)->default('user'); // user | admin | system
            $table->text('text');
            $table->string('file', 200)->nullable();            // снимок экрана: имя файла в storage
            $table->timestamps();
            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_messages');
        Schema::dropIfExists('feedback_tickets');
    }
};
