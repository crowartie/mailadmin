<?php

namespace Tests\Unit;

use App\Http\Controllers\Mail\Api\SettingsController;
use App\Models\Webmail\Setting;
use PHPUnit\Framework\TestCase;

/**
 * Схема «Стекло» (обращение №55): стандартные значения как в паспорте RECS (porcelain / liquid / expressive /
 * architecture 16% / стекло 78%), список палитр сервера совпадает с данными клиента, рисунков шесть.
 */
class SettingGlassTest extends TestCase
{
    public function test_стандарт_по_паспорту(): void
    {
        $this->assertSame('brand', Setting::DEFAULTS['scheme'], 'стекло — только по желанию, по умолчанию фирменная');
        $this->assertSame('porcelain', Setting::DEFAULTS['glass_palette']);
        $this->assertSame('expressive', Setting::DEFAULTS['glass_motion']);
        $this->assertSame('auto', Setting::DEFAULTS['glass_wallpaper']);
        $this->assertSame(16, Setting::DEFAULTS['glass_wallpaper_strength']);
        $this->assertSame(78, Setting::DEFAULTS['glass_density']);
    }

    public function test_палитры_и_рисунки_совпадают_с_данными_клиента(): void
    {
        $palettes = json_decode((string) file_get_contents(__DIR__ . '/../../resources/js/mail/glass-palettes.json'), true);
        $this->assertCount(20, $palettes);
        $this->assertSame(array_column($palettes, 'id'), SettingsController::GLASS_PALETTES, 'проверка сервера принимает ровно те же палитры');
        foreach ($palettes as $p) {
            foreach (['base', 'blue', 'soft', 'purple', 'haze', 'haze2', 'ink', 'muted', 'line', 'surface', 'overlay', 'rgb'] as $k) {
                $this->assertArrayHasKey($k, $p['tokens'], $p['id']);
            }
            if ($p['split']) {
                $this->assertArrayHasKey('side-rgb', $p['tokens'], $p['id'] . ': у split-палитры своё меню');
            }
        }
        $porcelain = array_values(array_filter($palettes, fn ($p) => $p['id'] === 'porcelain'))[0];
        $this->assertSame('#41639A', $porcelain['tokens']['blue'], 'стандартный акцент графиков из паспорта');
        $this->assertCount(4, array_filter($palettes, fn ($p) => $p['dark']), 'четыре тёмные');

        $wall = json_decode((string) file_get_contents(__DIR__ . '/../../resources/js/mail/glass-wallpapers.json'), true);
        $this->assertSame(['architecture', 'arches', 'petals', 'linen', 'contours', 'orbit'], array_keys($wall));
        foreach ($wall as $w) {
            $this->assertStringContainsString('viewBox="0 0 320 320"', $w['svg'], 'плитка 320×320');
            $this->assertStringContainsString('stroke="#41639A"', $w['svg'], 'цвет линий подменяется на акцент палитры');
        }
    }
}
