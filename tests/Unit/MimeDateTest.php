<?php

namespace Tests\Unit;

use App\Services\Mail\Mime;
use PHPUnit\Framework\TestCase;

/** Дата письма: кривой заголовок Date не должен уводить письмо в «Сентябрь 200 года». */
class MimeDateTest extends TestCase
{
    public function test_пояс_без_знака_не_становится_годом(): void
    {
        $d = Mime::parseDate('Thu, 17 September 2026 19:31:14 0200');
        $this->assertNotNull($d);
        $this->assertSame('2026-09-17T19:31:14+02:00', $d->toIso8601String());
    }

    public function test_обычная_дата_и_комментарий_пояса(): void
    {
        $this->assertSame('2026-09-10T10:07:26+03:00', Mime::parseDate('Thu, 10 Sep 2026 10:07:26 +0300 (MSK)')->toIso8601String());
    }

    public function test_невозможная_дата_не_принимается(): void
    {
        $this->assertNull(Mime::parseDate('Mon, 1 Jan 0200 10:00:00 +0000'));
        $this->assertNull(Mime::parseDate('Mon, 1 Jan 2099 10:00:00 +0000'));
        $this->assertNull(Mime::parseDate('не дата'));
        $this->assertNull(Mime::parseDate(''));
    }
}
