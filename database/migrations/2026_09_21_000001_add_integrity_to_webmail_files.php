<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Контроль целостности файлов хранилища и тема письма, к которому файл приложен. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webmail_files', function (Blueprint $table) {
            $table->string('sha256', 64)->nullable()->after('path');
            $table->string('subject', 255)->nullable()->after('message_id');
            $table->timestamp('checked_at')->nullable()->after('last_download_at');   // последняя проверка files:check
        });
    }

    public function down(): void
    {
        Schema::table('webmail_files', function (Blueprint $table) {
            $table->dropColumn(['sha256', 'subject', 'checked_at']);
        });
    }
};
