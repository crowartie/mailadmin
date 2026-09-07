<?php

namespace App\Services\Mail;

/**
 * Письмо без объявленной кодировки библиотека «угадывает» как ISO-8859-1/2 и перекодирует
 * в UTF-8: кириллица превращается в «Ð¿Ñ€Ð¸Ð²ÐµÑ‚» или «ĐŃĐ¸Đ˛ĐľŃ». Узнаём такой случай
 * и откатываем через ту же однобайтовую таблицу. Если кириллица уже есть — не трогаем.
 */
final class Charset
{
    /** Заголовок: если библиотека оставила =?utf-8?Q?…?= (так бывает при переносах), раскодировать самим. */
    public static function header(?string $s): ?string
    {
        if ($s !== null && str_contains($s, '=?')) {
            $decoded = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                $s = $decoded;
            }
        }

        return self::fix($s);
    }

    public static function fix(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return $s;
        }

        if (! mb_check_encoding($s, 'UTF-8')) {
            // Сырые байты: пробуем как UTF-8, потом как windows-1251.
            $utf = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            if (mb_check_encoding($utf, 'UTF-8') && preg_match('/[\x{0400}-\x{04FF}]/u', $utf)) {
                return $utf;
            }

            return mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
        }

        if (preg_match('/[\x{0400}-\x{04FF}]/u', $s) || ! preg_match('/[\x{0080}-\x{024F}]{2}/u', $s)) {
            return $s;
        }

        foreach (['ISO-8859-2', 'ISO-8859-1', 'Windows-1252', 'ISO-8859-4', 'ISO-8859-10'] as $table) {
            $back = @mb_convert_encoding($s, $table, 'UTF-8');
            if (is_string($back) && mb_check_encoding($back, 'UTF-8') && preg_match('/[\x{0400}-\x{04FF}]/u', $back)) {
                return $back;
            }
        }

        return $s;
    }
}
