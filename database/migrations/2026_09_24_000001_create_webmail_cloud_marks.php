<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Срок хранения файлов облака сотрудника: когда владелец последний раз обращался к файлу
 * (загрузил, открыл, скачал, приложил к письму, дал ссылку) и закреплён ли он. Файл хранится
 * N дней (по умолчанию 28) с последнего обращения, закреплённый — без срока.
 * path_hash — для уникального ключа: путь бывает длиннее, чем MySQL даёт проиндексировать.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webmail_cloud_marks', function (Blueprint $t) {
            $t->id();
            $t->string('user', 190);
            $t->char('path_hash', 40);
            $t->string('path', 1000);                  // относительно папки сотрудника
            $t->timestamp('last_access_at')->nullable();
            $t->boolean('pinned')->default(false);
            $t->timestamp('pinned_at')->nullable();
            $t->timestamps();
            $t->unique(['user', 'path_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webmail_cloud_marks');
    }
};
