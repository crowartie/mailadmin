<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ссылки на файлы из облака сотрудника выдаются тем же files-хостом, что и большие вложения:
 * запись в webmail_files есть, а сам файл лежит в Nextcloud (source = 'nc', path пустой).
 * password — хэш пароля ссылки, если сотрудник её защитил.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webmail_files', function (Blueprint $t) {
            $t->string('source', 8)->default('local')->index()->after('path');
            $t->string('password', 255)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('webmail_files', function (Blueprint $t) {
            $t->dropIndex(['source']);
            $t->dropColumn(['source', 'password']);
        });
    }
};
