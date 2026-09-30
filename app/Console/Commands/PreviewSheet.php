<?php

namespace App\Console\Commands;

use App\Services\Mail\SheetPreview;
use Illuminate\Console\Command;

/**
 * Разбор таблицы для просмотрщика — в отдельном процессе (его запускает SheetPreview с лимитом памяти
 * и времени): большая книга не должна занимать рабочий процесс почты.
 */
class PreviewSheet extends Command
{
    protected $signature = 'preview:sheet {src : файл таблицы} {out : куда записать JSON}';

    protected $description = 'Таблица (xls, xlsx, ods, csv) → JSON для просмотрщика вложений';

    protected $hidden = true;

    public function handle(): int
    {
        $data = SheetPreview::build((string) $this->argument('src'));
        file_put_contents((string) $this->argument('out'), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        return self::SUCCESS;
    }
}
