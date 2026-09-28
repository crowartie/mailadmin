<?php

namespace Tests\Unit;

use App\Support\Format;
use PHPUnit\Framework\TestCase;

/** Телефоны в контактах: любой ввод российского номера — к «+7 (902) 548-56-50», для звонка — «+79025485650». */
class PhoneFormatTest extends TestCase
{
    public function test_российский_номер_в_едином_виде(): void
    {
        foreach (['89025485650', '+7 902 548 56 50', '7 (902) 548-56-50', '9025485650', '8 (902) 548-56-50'] as $raw) {
            $this->assertSame('+7 (902) 548-56-50', Format::phone($raw), $raw);
            $this->assertSame('+79025485650', Format::phoneDial($raw), $raw);
        }
    }

    public function test_прочие_номера_не_трогаем(): void
    {
        $this->assertSame('1234', Format::phone('1234'));
        $this->assertSame('+380 44 123 45 67', Format::phone('+380  44 123 45 67'));
        $this->assertSame('+380441234567', Format::phoneDial('+380 44 123 45 67'));
        $this->assertSame('', Format::phone(null));
    }
}
