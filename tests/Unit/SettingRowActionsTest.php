<?php

namespace Tests\Unit;

use App\Models\Webmail\Setting;
use PHPUnit\Framework\TestCase;

/**
 * Кнопки при наведении на письмо (обращение №54) — по желанию: такая панель уже была у всех,
 * и по ней нажимали случайно. По умолчанию выключено, и выключенной остаётся у тех, кто ничего не менял.
 */
class SettingRowActionsTest extends TestCase
{
    public function test_по_умолчанию_выключено(): void
    {
        $this->assertFalse(Setting::DEFAULTS['row_actions']);
        $this->assertSame(5, Setting::DEFAULTS['undo_seconds'], 'удаление кнопкой при наведении отменяется в окне «Отменить»');
    }
}
