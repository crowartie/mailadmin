<?php

namespace App\Console\Commands;

use App\Services\Cloud\LocalFiles;
use Illuminate\Console\Command;

/**
 * Уборка хранилища больших вложений: удаляет файлы, у которых срок ссылки истёк
 * больше «keep_days» назад. По умолчанию keep_days = 0 — ничего не удаляется,
 * ссылку можно продлить в любой момент. Заодно чистит осиротевшие временные каталоги
 * конвертера предпросмотра.
 */
class FilesPurge extends Command
{
    protected $signature = 'files:purge';

    protected $description = 'Удалить файлы хранилища, срок которых давно истёк (по настройке keep_days)';

    public function handle(): int
    {
        $n = (new LocalFiles())->purge();
        $this->line($n ? "удалено файлов: {$n}" : 'удалять нечего');

        $dir = storage_path('app/private/preview');
        if (is_dir($dir)) {
            foreach (glob($dir . '/tmp-*') ?: [] as $tmp) {
                if (is_dir($tmp) && filemtime($tmp) < time() - 3600) {
                    \Illuminate\Support\Facades\File::deleteDirectory($tmp);
                }
            }
        }

        return self::SUCCESS;
    }
}
