<?php

namespace App\Console\Commands;

use App\Services\Mail\SharedReads;
use App\Services\Mail\SharedReplies;
use Illuminate\Console\Command;

/** Отметки «кто прочитал» в общих папках старше года — долой. */
class SharedReadsPurge extends Command
{
    protected $signature = 'shared-reads:purge';

    protected $description = 'Удалить отметки «кто прочитал» старше года';

    public function handle(): int
    {
        $this->info('удалено отметок «прочитал»: ' . SharedReads::purge() . ', ответов: ' . SharedReplies::purge());

        return self::SUCCESS;
    }
}
