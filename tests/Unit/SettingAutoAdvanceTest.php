<?php

namespace Tests\Unit;

use App\Models\Webmail\Setting;
use PHPUnit\Framework\TestCase;

/**
 * Разбор почты подряд, как в Яндексе (обращение №62): после удаления открытого письма — следующее или
 * предыдущее, при входе — первое письмо. По умолчанию выключено: у остальных сотрудников всё как было.
 */
class SettingAutoAdvanceTest extends TestCase
{
    public function test_по_умолчанию_как_было(): void
    {
        $this->assertSame('list', Setting::DEFAULTS['after_remove']);
        $this->assertFalse(Setting::DEFAULTS['open_first']);
    }
}
