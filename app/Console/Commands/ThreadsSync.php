<?php

namespace App\Console\Commands;

use App\Models\Vmail\Mailbox;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use App\Services\Mail\ThreadIndex;
use Illuminate\Console\Command;

/** Индекс цепочек ответов: дочитать новое по всем ящикам (планировщик) или перестроить один ящик. */
class ThreadsSync extends Command
{
    protected $signature = 'threads:sync {user? : адрес ящика} {--all : все активные ящики} {--rebuild : перестроить с нуля}';

    protected $description = 'Индекс цепочек ответов: дочитать новые письма или перестроить';

    public function handle(): int
    {
        $users = $this->option('all')
            ? Mailbox::query()->where('active', 1)->orderBy('username')->pluck('username')->all()
            : array_filter([strtolower(trim((string) $this->argument('user')))]);
        if (! $users) {
            $this->error('Укажите ящик или --all');

            return self::FAILURE;
        }
        $rc = self::SUCCESS;
        $t0 = microtime(true);
        foreach ($users as $user) {
            try {
                $store = new MailStore(ImapSession::master($user));
                if ($this->option('rebuild')) {
                    ThreadIndex::forgetUser($user);
                }
                $s = ThreadIndex::sync($store, [], (bool) $this->option('rebuild'));
                if ($s['added'] || $s['removed'] || $s['rebuilt'] || $this->output->isVerbose()) {
                    $this->line(sprintf('%-32s папок %2d, добавлено %5d, убрано %4d%s', $user, $s['folders'], $s['added'], $s['removed'], $s['rebuilt'] ? ", перестроено {$s['rebuilt']}" : ''));
                }
            } catch (\Throwable $e) {
                $rc = self::FAILURE;
                $this->error($user . ': ' . mb_substr($e->getMessage(), 0, 200));
            }
        }
        if ($this->output->isVerbose() || count($users) > 1) {
            $this->info(sprintf('%d ящиков за %.1f с', count($users), microtime(true) - $t0));
        }

        return $rc;
    }
}
