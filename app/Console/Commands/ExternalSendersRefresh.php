<?php

namespace App\Console\Commands;

use App\Services\Server\ExternalSenders;
use Illuminate\Console\Command;

/** Раз в сутки перечитать SPF-диапазоны сервисов (mail.ru, Яндекс…) и обновить карты Postfix. */
class ExternalSendersRefresh extends Command
{
    protected $signature = 'external-senders:refresh';

    protected $description = 'Обновить диапазоны серверов для отправки с чужих сервисов';

    public function handle(ExternalSenders $ext): int
    {
        try {
            $ext->apply(fresh: true);
            $this->info('готово');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
