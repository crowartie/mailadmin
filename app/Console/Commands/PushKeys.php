<?php

namespace App\Console\Commands;

use App\Services\Mail\PushNotifier;
use Illuminate\Console\Command;

/**
 * Ключи VAPID для push-уведомлений. Печатает строки для .env; deploy/dovecot-push.sh
 * зовёт команду с --dotenv и дописывает их сам, если ключей ещё нет.
 */
class PushKeys extends Command
{
    protected $signature = 'push:keys {--dotenv : напечатать строками PUSH_VAPID_PUBLIC=… / PUSH_VAPID_PRIVATE=… для .env}';

    protected $description = 'Создать пару ключей VAPID для push-уведомлений';

    public function handle(): int
    {
        $k = PushNotifier::makeKeys();
        if ($this->option('dotenv')) {
            $this->line('PUSH_VAPID_PUBLIC=' . $k['public']);
            $this->line('PUSH_VAPID_PRIVATE=' . $k['private']);
        } else {
            $this->info('Открытый ключ:  ' . $k['public']);
            $this->info('Закрытый ключ:  ' . $k['private']);
            $this->line('Добавьте их в .env как PUSH_VAPID_PUBLIC и PUSH_VAPID_PRIVATE и выполните php artisan optimize.');
        }

        return self::SUCCESS;
    }
}
