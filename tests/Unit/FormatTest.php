<?php

namespace Tests\Unit;

use App\Support\Format;
use Tests\TestCase;

/**
 * Размер и склонение показываются в письме, в очереди, в журнале и в распечатке.
 * Раньше это были пять разных копий расчёта, и один файл выглядел по-разному
 * в зависимости от страницы — здесь закреплён единственный правильный ответ.
 */
class FormatTest extends TestCase
{
    public function test_размер_файла(): void
    {
        $this->assertSame('0 Б', Format::size(0));
        $this->assertSame('812 Б', Format::size(812));
        $this->assertSame('1 КБ', Format::size(1024));
        $this->assertSame('500 КБ', Format::size(512000));
        // Единицу выбираем по округлённому значению: иначе выходило «1024 КБ».
        $this->assertSame('1 МБ', Format::size(1048575));
        $this->assertSame('1,4 МБ', Format::size(1468006));
        $this->assertSame('2 МБ', Format::size(2097152), 'ровный размер — без запятой');
        $this->assertSame('1 ГБ', Format::size(1073741824));
        $this->assertSame('', Format::size(null));
    }

    public function test_склонение(): void
    {
        $this->assertSame('письмо', Format::plural(1, 'письмо', 'письма', 'писем'));
        $this->assertSame('письма', Format::plural(3, 'письмо', 'письма', 'писем'));
        $this->assertSame('писем', Format::plural(5, 'письмо', 'письма', 'писем'));
        $this->assertSame('писем', Format::plural(11, 'письмо', 'письма', 'писем'), 'одиннадцать — не «письмо»');
        $this->assertSame('письмо', Format::plural(21, 'письмо', 'письма', 'писем'));
        $this->assertSame('письма', Format::plural(22, 'письмо', 'письма', 'писем'));
        $this->assertSame('писем', Format::plural(112, 'письмо', 'письма', 'писем'), 'сто двенадцать — тоже исключение');
        $this->assertSame('писем', Format::plural(0, 'письмо', 'письма', 'писем'));
    }

    public function test_число_со_словом(): void
    {
        $this->assertSame('3 письма', Format::count(3, 'письмо', 'письма', 'писем'));
    }
}
