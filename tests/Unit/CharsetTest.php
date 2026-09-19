<?php

namespace Tests\Unit;

use App\Services\Mail\Charset;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Кодировки писем. Каждый случай здесь — разбор реальной жалобы «вместо текста каша»:
 * письма в koi8-r и cp866 безусловно читались как windows-1251, смешанная строка
 * возвращалась как есть, а заголовок с именем кодировки «utf8» оставался на экране
 * служебной записью =?utf8?B?…?=.
 */
class CharsetTest extends TestCase
{
    /** Пустое закодированное слово — это пустая тема, а не служебная запись в списке. */
    public function test_пустое_закодированное_слово_даёт_пустую_строку(): void
    {
        $this->assertSame('', Charset::header('=?utf-8?B??='));
        $this->assertSame('', Charset::header('=?UTF-8?Q??= =?UTF-8?Q??='));
        $this->assertSame('Тема', Charset::header('=?utf-8?B?0KLQtdC80LA=?='));
    }

    private const RU = 'Привет, коллеги! Счёт на оплату во вложении.';

    /** Однобайтовые кодировки определяются по виду текста, а не берутся всегда windows-1251. */
    #[DataProvider('encodings')]
    public function test_подбирает_кодировку(string $encoding): void
    {
        $bytes = mb_convert_encoding(self::RU, $encoding, 'UTF-8');

        $this->assertSame(self::RU, Charset::fix($bytes), "не разобралась кодировка {$encoding}");
    }

    public static function encodings(): array
    {
        return [
            'windows-1251' => ['Windows-1251'],
            'koi8-r' => ['KOI8-R'],
            'cp866' => ['CP866'],
            'iso-8859-5' => ['ISO-8859-5'],
        ];
    }

    /** Кракозябра целиком: UTF-8, прочитанный как латиница-1. */
    public function test_чинит_кракозябру(): void
    {
        $moji = mb_convert_encoding(self::RU, 'UTF-8', 'ISO-8859-1');

        $this->assertNotSame(self::RU, $moji, 'проверка составлена неверно: строка не испорчена');
        $this->assertSame(self::RU, Charset::fix($moji));
    }

    /**
     * Смешанная строка: испорчен только кусок. Раньше перевод всей строки уничтожал
     * настоящую кириллицу рядом, а при одной верной букве строка вообще не чинилась.
     */
    public function test_чинит_только_испорченный_кусок(): void
    {
        $mixed = mb_convert_encoding('Отчёт', 'UTF-8', 'ISO-8859-1') . ' за сентябрь';

        $this->assertSame('Отчёт за сентябрь', Charset::fix($mixed));
    }

    /** Нормальный текст трогать нельзя — в том числе европейский с диакритикой. */
    #[DataProvider('untouched')]
    public function test_не_портит_нормальный_текст(string $text): void
    {
        $this->assertSame($text, Charset::fix($text));
    }

    public static function untouched(): array
    {
        return [
            'русский' => [self::RU],
            'немецкий' => ['Grüße aus München, Straße 5'],
            'французский' => ['Café Crème — naïve'],
            'польский' => ['Ostrów Wielkopolski'],
            'смешанный' => ['Re: договор №5 — Acme Ltd.'],
            'кавычки' => ['«Ёлка» и Ко.'],
        ];
    }

    /** Заголовки с нестандартным именем кодировки: utf8, cp1251, koi8r. */
    #[DataProvider('headers')]
    public function test_раскрывает_заголовок(string $charsetName, string $encoding, string $expected): void
    {
        $encoded = '=?' . $charsetName . '?B?' . base64_encode(mb_convert_encoding($expected, $encoding, 'UTF-8')) . '?=';

        $this->assertSame($expected, Charset::header($encoded));
    }

    public static function headers(): array
    {
        return [
            'utf8 без дефиса' => ['utf8', 'UTF-8', 'Счёт №17 от 17.09'],
            'cp1251' => ['cp1251', 'Windows-1251', 'Договор поставки'],
            'koi8r' => ['koi8r', 'KOI8-R', 'Акт сверки'],
            'обычный utf-8' => ['utf-8', 'UTF-8', 'Обычная тема'],
        ];
    }

    /** Служебная запись не должна оставаться на экране, даже если раскодировать не вышло. */
    public function test_не_оставляет_служебную_запись(): void
    {
        $this->assertStringNotContainsString('=?', (string) Charset::header('=?utf8?B?' . base64_encode('Тема') . '?='));
    }

    /** Имя вложения без кавычек: «Счёт за май.pdf» терялся по первому пробелу. */
    public function test_имя_вложения_с_пробелами(): void
    {
        $raw = "Content-Type: application/pdf\r\nContent-Disposition: attachment; filename=Счёт за май.pdf\r\n";

        $this->assertSame('Счёт за май.pdf', Charset::attachmentName($raw));
    }

    /** Имя, разрезанное по RFC 2231 на куски. */
    public function test_имя_вложения_из_кусков(): void
    {
        $raw = "Content-Disposition: attachment;\r\n filename*0*=utf-8''%D0%A1%D1%87%D1%91%D1%82;\r\n filename*1*=.pdf\r\n";

        $this->assertSame('Счёт.pdf', Charset::attachmentName($raw));
    }
}
