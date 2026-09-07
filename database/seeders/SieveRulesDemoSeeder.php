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
            ['adv@innotec.su', 'Арсентьев', 'fileinto', 'Тема содержит «Счёт» или «Акт»', 'Переместить в «Бухгалтерия», Пометить важным', true, null],
            ['adv@innotec.su', 'Арсентьев', 'redirect', 'Отправитель содержит «irkutskenergo.ru»', 'Копия на info@innotec.su', true, null],
            ['info@innotec.su', 'Общий ящик', 'fileinto', 'Тема содержит «***SPAM***»', 'Переместить в «Спам», остановить обработку', true, null],
            ['koviazinsa@innotec.su', 'Ковязин С. А.', 'vacation', 'Любое входящее', 'Автоответ «В отпуске до 15 сентября»', true, '2026-09-15'],
            ['petrovaa@innotec.su', 'Петров Алексей', 'fileinto', 'Отправитель содержит «supplier@example.org»', 'Переместить в «Поставщики»', false, null],
            ['test@innotec.su', 'Тестовый ящик', 'redirect', 'Письмо больше 10 МБ', 'Переслать на archive@innotec.su', true, null],
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
