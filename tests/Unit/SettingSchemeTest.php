<?php

namespace Tests\Unit;

use App\Models\Webmail\Setting;
use PHPUnit\Framework\TestCase;

/** Цветовая схема — настройка ящика: по умолчанию фирменная; допустимые значения проверяет SettingsController. */
class SettingSchemeTest extends TestCase
{
    public function test_по_умолчанию_фирменная(): void
    {
        $this->assertSame('brand', Setting::DEFAULTS['scheme']);
        $this->assertSame('light', Setting::DEFAULTS['theme']);
    }
}
