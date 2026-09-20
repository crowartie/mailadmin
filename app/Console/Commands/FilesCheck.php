<?php

namespace App\Console\Commands;

use App\Services\Cloud\LocalFiles;
use Illuminate\Console\Command;

/**
 * Проверка хранилища больших вложений: каждый файл на месте и того размера, что записан;
 * с --hash — сверяется и контрольная сумма (долго на сотнях гигабайт, поэтому раз в неделю).
 * Файлы на диске, которых нет в базе, удаляются (старше суток).
 */
class FilesCheck extends Command
{
    protected $signature = 'files:check {--hash : сверить и контрольные суммы}';

    protected $description = 'Проверить целостность файлов хранилища больших вложений';

    public function handle(): int
    {
        $r = (new LocalFiles())->check((bool) $this->option('hash'));
        $this->line('проверено: ' . $r['checked'] . ', лишних на диске убрано: ' . $r['orphans']);
        foreach ($r['broken'] as $line) {
            $this->error('повреждён: ' . $line);
        }

        return $r['broken'] ? self::FAILURE : self::SUCCESS;
    }
}
