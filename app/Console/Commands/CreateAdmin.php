<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email} {--name=Администратор} {--password= : если не задан — сгенерировать}';

    protected $description = 'Создать администратора панели';

    public function handle(): int
    {
        $email = strtolower($this->argument('email'));
        $password = $this->option('password') ?: Str::password(16, symbols: false);

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $this->option('name'), 'password' => $password, 'is_active' => true]
        );

        $this->info("Администратор {$user->email} " . ($user->wasRecentlyCreated ? 'создан' : 'обновлён') . '.');
        $this->line("Пароль: {$password}");
        $this->comment('Включите 2FA после первого входа: Безопасность → Двухфакторная защита.');

        return self::SUCCESS;
    }
}
