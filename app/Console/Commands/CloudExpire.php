<?php

namespace App\Console\Commands;

use App\Services\Cloud\DbCloudLedger;
use App\Services\Cloud\PersonalCloud;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Облако сотрудника: файлы, к которым владелец не обращался дольше срока (по умолчанию 28 дней;
 * при действующей ссылке — срок после её окончания), уходят в корзину. Закреплённое не трогаем.
 */
class CloudExpire extends Command
{
    protected $signature = 'cloud:expire';

    protected $description = 'Облако сотрудника: убрать в корзину файлы с истёкшим сроком хранения';

    public function handle(): int
    {
        if (! PersonalCloud::enabled()) {
            return self::SUCCESS;
        }
        $s = PersonalCloud::settings();
        $ledger = new DbCloudLedger();
        $total = 0;
        foreach ($ledger->cloudUsers() as $user) {
            try {
                $gone = (new PersonalCloud($user, $s, $ledger))->expire();
                if ($gone) {
                    $total += count($gone);
                    Log::info('cloud:expire ' . $user . ': в корзину по сроку — ' . count($gone));
                }
            } catch (\Throwable $e) {
                $this->warn($user . ': ' . $e->getMessage());
            }
        }
        $this->line('убрано в корзину по сроку: ' . $total);

        return self::SUCCESS;
    }
}
