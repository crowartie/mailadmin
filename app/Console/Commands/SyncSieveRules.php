<?php

namespace App\Console\Commands;

use App\Models\SieveRule;
use App\Models\Vmail\Mailbox;
use App\Services\Sieve\SieveScriptReader;
use Illuminate\Console\Command;

/**
 * Читает активные Sieve-скрипты сотрудников с диска почтового сервера
 * и раскладывает их в таблицу sieve_rules человеческим языком.
 *
 * Запускать на машине, где смонтирован /var/vmail (или по cron через агента).
 */
class SyncSieveRules extends Command
{
    protected $signature = 'rules:sync {--user= : только один адрес}';

    protected $description = 'Синхронизировать правила сотрудников из Sieve-скриптов Dovecot';

    public function handle(SieveScriptReader $reader): int
    {
        $query = Mailbox::query()->where('active', 1);
        if ($user = $this->option('user')) {
            $query->where('username', $user);
        }

        $total = 0;
        $missing = 0;

        foreach ($query->cursor() as $mailbox) {
            $script = $reader->activeScript($mailbox);

            if ($script === null) {
                $missing++;
                SieveRule::where('owner', $mailbox->username)->delete();
                continue;
            }

            $rules = $reader->parse($script);

            SieveRule::where('owner', $mailbox->username)->delete();
            foreach ($rules as $i => $rule) {
                SieveRule::create($rule + [
                    'owner' => $mailbox->username,
                    'owner_name' => $mailbox->name,
                    'position' => $i,
                    'synced_at' => now(),
                ]);
            }

            $total += count($rules);
        }

        $this->info("Правил: {$total}. Ящиков без скрипта: {$missing}.");

        return self::SUCCESS;
    }
}
