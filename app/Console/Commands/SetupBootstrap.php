<?php

namespace App\Console\Commands;

use App\Models\Vmail\Alias;
use App\Models\Vmail\Mailbox;
use App\Services\Server\Throttle;
use App\Services\Vmail\AliasService;
use Illuminate\Console\Command;

/**
 * Первичная настройка данных после установки (вызывается deploy/install.sh, повторный запуск безопасен):
 *  - псевдоним noreply@домен → postmaster (от него уходят уведомления, сводки, напоминания);
 *  - общий лимит исходящих писем на сотрудника (iRedAPD throttle).
 */
class SetupBootstrap extends Command
{
    protected $signature = 'setup:bootstrap {--throttle=200 : писем в час на сотрудника, 0 — без лимита}';

    protected $description = 'Данные, без которых почта не работает как задумано: noreply@, лимит исходящих';

    public function handle(AliasService $aliases): int
    {
        $domain = (string) config('areas.default_domain');
        $noreply = 'noreply@' . $domain;
        $postmaster = 'postmaster@' . $domain;
        if (! Alias::query()->find($noreply) && ! Mailbox::query()->find($noreply)) {
            if (Mailbox::query()->find($postmaster)) {
                $aliases->create(['address' => $noreply, 'name' => 'Уведомления сервера', 'targets' => [$postmaster]]);
                $this->info("Псевдоним {$noreply} → {$postmaster} создан");
            } else {
                $this->warn("Ящика {$postmaster} нет — псевдоним noreply@ не создан");
            }
        } else {
            $this->line("{$noreply} уже есть");
        }

        try {
            $cur = Throttle::get();
            $want = (int) $this->option('throttle');
            if (empty($cur['enabled']) && $want > 0) {
                Throttle::set($want, 60);
                $this->info("Лимит исходящих: {$want} писем в час на сотрудника");
            } else {
                $this->line('Лимит исходящих: ' . (! empty($cur['enabled']) ? $cur['max_msgs'] . ' за ' . $cur['period_min'] . ' мин' : 'не задан'));
            }
        } catch (\Throwable $e) {
            $this->warn('iRedAPD throttle: ' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}
