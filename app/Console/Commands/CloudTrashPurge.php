<?php

namespace App\Console\Commands;

use App\Services\Cloud\DbCloudLedger;
use App\Services\Cloud\PersonalCloud;
use Illuminate\Console\Command;

/** Корзина личного облака: удалённое дольше срока (по умолчанию 30 дней) стирается из Nextcloud. */
class CloudTrashPurge extends Command
{
    protected $signature = 'cloud:purge-trash';

    protected $description = 'Личное облако: стереть из корзины всё старше срока хранения';

    public function handle(): int
    {
        if (! PersonalCloud::enabled()) {
            return self::SUCCESS;
        }
        $s = PersonalCloud::settings();
        $days = max(1, (int) ($s['personal_trash_days'] ?? 30));
        $ledger = new DbCloudLedger();
        $n = 0;
        foreach ($ledger->trashOlderThan(now()->subDays($days)) as $t) {
            try {
                (new PersonalCloud($t['user'], $s, $ledger))->purge((int) $t['id']);
                $n++;
            } catch (\Throwable $e) {
                $this->warn($t['user'] . ': ' . $e->getMessage());
            }
        }
        $this->line('стёрто из корзин: ' . $n);

        return self::SUCCESS;
    }
}
