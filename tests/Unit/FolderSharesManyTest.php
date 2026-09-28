<?php

namespace Tests\Unit;

use App\Services\Mail\FolderShares;
use PHPUnit\Framework\TestCase;

/**
 * Перечень папок одним уровнем (строка сотрудника на «Общем доступе»): «Входящие» раздаются первыми,
 * потому что set('INBOX') снимает права с остальных папок; «владелец» — только через «Входящие» и на весь ящик.
 */
class FolderSharesManyTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
    }

    public function test_входящие_первыми_и_без_повторов(): void
    {
        $svc = \Mockery::mock(FolderShares::class)->makePartial();
        $calls = [];
        $svc->shouldReceive('set')->andReturnUsing(function ($owner, $folder, $with, $level) use (&$calls) { $calls[] = [$folder, $level]; });

        $n = $svc->setMany('info@example.test', ['Отправленные', 'INBOX', 'Закупки', 'Отправленные'], 'anna@example.test', 'editor');

        $this->assertSame(3, $n);
        $this->assertSame([['INBOX', 'editor'], ['Отправленные', 'editor'], ['Закупки', 'editor']], $calls);
    }

    public function test_владелец_это_весь_ящик_через_входящие(): void
    {
        $svc = \Mockery::mock(FolderShares::class)->makePartial();
        $svc->shouldReceive('set')->once()->with('info@example.test', 'INBOX', 'anna@example.test', 'owner');

        $this->assertSame(1, $svc->setMany('info@example.test', ['Закупки', 'Отправленные'], 'anna@example.test', 'owner'));
    }

    public function test_закрыть_весь_ящик_это_снятие_по_входящим(): void
    {
        $svc = \Mockery::mock(FolderShares::class)->makePartial();
        $svc->shouldReceive('remove')->once()->with('info@example.test', 'INBOX', 'anna@example.test');

        $svc->removeAll('info@example.test', 'anna@example.test');
        $this->addToAssertionCount(1);   // ожидание Mockery выше — и есть проверка
    }
}
