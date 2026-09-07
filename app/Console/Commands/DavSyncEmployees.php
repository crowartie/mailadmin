<?php

namespace App\Console\Commands;

use App\Services\Dav\EmployeeBook;
use Illuminate\Console\Command;

/** Сверить общую книгу «Сотрудники» с ящиками сервера. */
class DavSyncEmployees extends Command
{
    protected $signature = 'dav:sync-employees';

    protected $description = 'Обновить общую адресную книгу «Сотрудники» по таблице ящиков';

    public function handle(EmployeeBook $book): int
    {
        $stats = $book->sync();
        $this->info(sprintf('Сотрудники: добавлено %d, обновлено %d, удалено %d', $stats['added'], $stats['updated'], $stats['removed']));

        return self::SUCCESS;
    }
}
