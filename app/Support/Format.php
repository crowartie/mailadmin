<?php

namespace App\Support;

/**
 * Числа человеку: размер файла и склонение слова при числе.
 *
 * Оба расчёта до этого лежали в пяти местах разными копиями и расходились по краям —
 * один и тот же файл показывался как «1.0 МБ» в очереди и «1 МБ» в письме, а гигабайт
 * где-то превращался в «1024 МБ». Правила здесь те же, что в resources/js/mail/format.js.
 */
final class Format
{
    /** Размер в байтах словами: «812 КБ», «1,4 МБ». */
    public static function size(?int $bytes): string
    {
        if ($bytes === null) {
            return '';
        }
        if ($bytes < 1024) {
            return $bytes . ' Б';
        }
        // Единицу выбираем по уже округлённому значению: иначе 1 048 575 Б показывались
        // как «1024 КБ», а почти гигабайт — как «1024 МБ».
        $kb = (int) round($bytes / 1024);
        if ($kb < 1024) {
            return $kb . ' КБ';
        }
        $mb = $bytes / 1048576;

        return $mb < 1023.95 ? self::round1($mb) . ' МБ' : self::round1($mb / 1024) . ' ГБ';
    }

    /** Слово при числе: plural(3, 'письмо', 'письма', 'писем') → «письма». */
    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n);
        $m10 = $n % 10;
        $m100 = $n % 100;
        if ($m10 === 1 && $m100 !== 11) {
            return $one;
        }
        if ($m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20)) {
            return $few;
        }

        return $many;
    }

    /** Число с числом и словом: «3 письма». */
    public static function count(int $n, string $one, string $few, string $many): string
    {
        return $n . ' ' . self::plural($n, $one, $few, $many);
    }

    /** Один знак после запятой, но без «,0» — «1,4 МБ» и «2 МБ». */
    private static function round1(float $v): string
    {
        $s = number_format($v, 1, ',', ' ');

        return str_ends_with($s, ',0') ? substr($s, 0, -2) : $s;
    }
}
