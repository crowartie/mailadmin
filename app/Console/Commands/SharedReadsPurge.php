<?php

namespace App\Console\Commands;

use App\Services\Mail\SharedReads;
use Illuminate\Console\Command;

/** Отметки «кто прочитал» в общих папках старше года — долой. */
class SharedReadsPurge extends Command
{
    protected $signature = 'shared-reads:purge';

    protected $description = 'Удалить отметки «кто прочитал» старше года';

    public function handle(): int
    {
        $this->info('удалено: ' . SharedReads::purge());

        return self::SUCCESS;
    }
}
