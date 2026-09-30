<?php

namespace Tests\Unit;

use App\Services\Mail\ImagePreview;
use App\Services\Mail\OfficePdf;
use Tests\TestCase;

/**
 * Просмотр вложений разных форматов (обращение №64): что переводится в PDF и чем, HEIC → JPEG, а на сервере
 * с установленными инструментами (deploy/preview-tools.sh) — настоящие переводы скана TIFF и чертежа DXF.
 */
class PreviewFormatsTest extends TestCase
{
    public function test_какие_форматы_переводятся(): void
    {
        foreach (['a.docx', 'b.xlsb', 'c.vsdx', 'd.pub', 'e.cdr', 'f.pages', 'g.numbers', 'h.key', 'i.ppsx', 'j.odg', 'k.tif', 'l.TIFF', 'm.dxf', 'n.DWG'] as $n) {
            $this->assertTrue(OfficePdf::supports($n), $n);
        }
        foreach (['a.pdf', 'b.jpg', 'c.heic', 'd.zip', 'e.mp4', 'dwg'] as $n) {
            $this->assertFalse(OfficePdf::supports($n), $n);
        }
        $this->assertTrue(ImagePreview::supports('IMG_0001.HEIC'));
        $this->assertTrue(ImagePreview::supports('photo', 'image/heif'));
        $this->assertFalse(ImagePreview::supports('a.jpg', 'image/jpeg'));
    }

    public function test_тип_видео_и_звука_по_расширению(): void
    {
        $m = \App\Http\Controllers\Mail\Api\MessageController::class;
        $this->assertSame('video/quicktime', $m::mediaType('IMG_0042.MOV'));
        $this->assertSame('video/mp4', $m::mediaType('clip.mp4'));
        $this->assertSame('audio/mpeg', $m::mediaType('Запись.mp3'));
        $this->assertSame('audio/mp4', $m::mediaType('voice.m4a'));
        $this->assertNull($m::mediaType('doc.pdf'));
    }

    public function test_многостраничный_скан_tiff_в_pdf(): void
    {
        if (! is_executable('/usr/bin/tiff2pdf')) {
            $this->markTestSkipped('нет tiff2pdf — ставит deploy/preview-tools.sh');
        }
        $pdf = OfficePdf::convertContent(base64_decode('SUkqAC4AAAB4nPv/fxQMZvA+no8odTaT+IlS9/w/cer+Dxt1o2A4AQDfLKIUAAkAAAEDAAEAAAAoAAAAAQEDAAEAAAAeAAAAAgEDAAEAAAAIAAAAAwEDAAEAAAAIAAAABgEDAAEAAAABAAAAEQEEAAEAAAAIAAAAFgEDAAEAAAAeAAAAFwEEAAEAAAAlAAAAHAEDAAEAAAABAAAA7gAAAElJKgBOAAAAeJz7/38UDGYw10Z/JxHKXtn9va5BhLrrq/5/ESXO5gVJRCm7o/2KGGWfjU8Qo+xf0DKibJ3HbW/vTZTKUTCsAADCTJ5iAAkAAAEDAAEAAAAoAAAAAQEDAAEAAAAeAAAAAgEDAAEAAAAIAAAAAwEDAAEAAAAIAAAABgEDAAEAAAABAAAAEQEEAAEAAACoAAAAFgEDAAEAAAAeAAAAFwEEAAEAAABFAAAAHAEDAAEAAAABAAAAAAAAAA=='), 'скан.tif');
        $body = (string) file_get_contents($pdf);
        $this->assertStringStartsWith('%PDF', $body);
        $this->assertSame(2, preg_match_all('#/Type\s*/Page[^s]#', $body), 'обе страницы скана');
    }

    public function test_чертёж_dxf_в_pdf(): void
    {
        if (! is_executable('/usr/bin/rsvg-convert') || ! is_file('/usr/lib/python3/dist-packages/ezdxf/__init__.py')) {
            $this->markTestSkipped('нет ezdxf или rsvg-convert — ставит deploy/preview-tools.sh');
        }
        // Минимальный DXF R12: отрезок, окружность и надпись.
        $dxf = implode("\n", ['0', 'SECTION', '2', 'ENTITIES',
            '0', 'LINE', '8', '0', '10', '0', '20', '0', '30', '0', '11', '100', '21', '50', '31', '0',
            '0', 'CIRCLE', '8', '0', '10', '50', '20', '25', '30', '0', '40', '20',
            '0', 'TEXT', '8', '0', '10', '10', '20', '60', '30', '0', '40', '5', '1', 'Чертёж ' . uniqid(),
            '0', 'ENDSEC', '0', 'EOF']) . "\n";
        $pdf = OfficePdf::convertContent($dxf, 'деталь.dxf');
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($pdf));
    }

    public function test_без_libredwg_понятный_отказ_для_dwg(): void
    {
        config(['mailadmin.dwg2dxf' => '/нет/такого/dwg2dxf']);
        try {
            OfficePdf::convertContent('AC1032' . str_repeat("\0", 100) . uniqid(), 'план.dwg');
            $this->fail('без LibreDWG перевода быть не должно');
        } catch (\App\Exceptions\MailException $e) {
            $this->assertStringContainsString('DWG', $e->getMessage());
        }
    }
}
