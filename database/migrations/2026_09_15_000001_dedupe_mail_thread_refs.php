<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * mail_thread_refs копилась без уникального ключа: каждое письмо длинной цепочки добавляло все свои References
 * заново — 9 млн строк и 2,6 ГБ на 125 тыс. писем, постоянная запись на диск при каждом обходе threads:sync.
 * Пересобираем таблицу с уникальностью (user, ref_id, thread_id); дальше вставки идут через insertOrIgnore.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_thread_refs') || Schema::hasTable('mail_thread_refs_new')) {
            return;
        }
        DB::statement('CREATE TABLE mail_thread_refs_new LIKE mail_thread_refs');
        DB::statement('ALTER TABLE mail_thread_refs_new ADD UNIQUE KEY mail_thread_refs_unique (`user`, `ref_id`, `thread_id`)');
        // INSERT IGNORE: дубли отбрасываются уникальным ключом; порядок id не важен.
        DB::statement('INSERT IGNORE INTO mail_thread_refs_new (`user`, `ref_id`, `thread_id`) SELECT `user`, `ref_id`, `thread_id` FROM mail_thread_refs');
        DB::statement('RENAME TABLE mail_thread_refs TO mail_thread_refs_old, mail_thread_refs_new TO mail_thread_refs');
        DB::statement('DROP TABLE mail_thread_refs_old');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mail_thread_refs DROP INDEX mail_thread_refs_unique');
    }
};
