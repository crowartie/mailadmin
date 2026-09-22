<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Журнал действий сотрудников: строки старше срока хранения удаляются кусками. */
class ActivityPrune extends Command
{
    protected $signature = 'activity:prune {--days=90 : сколько дней хранить}';

    protected $description = 'Удалить записи журнала действий старше срока хранения';

    public function handle(): int
    {
        $before = now()->subDays(max(7, (int) $this->option('days')));
        $total = 0;
        do {
            $n = DB::table('webmail_activity')->where('at', '<', $before)->limit(5000)->delete();
            $total += $n;
        } while ($n > 0);
        $this->line("удалено записей: {$total}");

        return self::SUCCESS;
    }
}
