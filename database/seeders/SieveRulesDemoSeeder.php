<?php

namespace Database\Seeders;

use App\Models\SieveRule;
use Illuminate\Database\Seeder;

/**
 * Демонстрационные правила для dev-стенда, где нет Dovecot.
 * На боевом сервере таблицу наполняет `php artisan rules:sync`.
 */
class SieveRulesDemoSeeder extends Seeder
{
    public function run(): void
    {
        SieveRule::query()->delete();

        $rows = [
            ['buh@example.ru', 'Бухгалтерия', 'fileinto', 'Тема содержит «Счёт» или «Акт»', 'Переместить в «Бухгалтерия», Пометить важным', true, null],
            ['buh@example.ru', 'Бухгалтерия', 'redirect', 'Отправитель содержит «energo.example.org»', 'Копия на info@example.ru', true, null],
            ['info@example.ru', 'Общий ящик', 'fileinto', 'Тема содержит «***SPAM***»', 'Переместить в «Спам», остановить обработку', true, null],
            ['ivanov@example.ru', 'Иванов И. И.', 'vacation', 'Любое входящее', 'Автоответ «В отпуске до 15 сентября»', true, '2026-09-15'],
            ['petrov@example.ru', 'Петров П. П.', 'fileinto', 'Отправитель содержит «supplier@example.org»', 'Переместить в «Поставщики»', false, null],
            ['test@example.ru', 'Тестовый ящик', 'redirect', 'Письмо больше 10 МБ', 'Переслать на archive@example.ru', true, null],
        ];

        foreach ($rows as $i => [$owner, $name, $kind, $cond, $action, $active, $until]) {
            SieveRule::create([
                'owner' => $owner, 'owner_name' => $name, 'kind' => $kind, 'condition' => $cond,
                'action' => $action, 'active' => $active, 'until' => $until, 'position' => $i,
                'raw' => '# demo', 'synced_at' => now(),
            ]);
        }
    }
}
