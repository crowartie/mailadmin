<?php

namespace App\Console\Commands;

use App\Services\Server\Alerts;
use App\Services\Server\MailLog;
use Illuminate\Console\Command;

/** Проверить очередь, диск, службы, сертификат, копии — и оповестить администраторов. */
class AlertsCheck extends Command
{
    protected $signature = 'alerts:check {--digest : отправить ежедневную сводку}';

    protected $description = 'Уведомления администраторам о проблемах сервера';

    public function handle(Alerts $alerts, MailLog $log): int
    {
        if ($this->option('digest')) {
            $alerts->digest($log);
            $this->info('Сводка отправлена');

            return self::SUCCESS;
        }
        $sent = $alerts->check();
        $this->info($sent ? implode("\n", $sent) : 'Всё в порядке');

        return self::SUCCESS;
    }
}
