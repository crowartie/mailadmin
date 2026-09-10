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
            // Outlook склеивает encoded-word без пробела («?==?utf-8?B?…») и переносит внутри слова — приводим к RFC 2047.
            $s = preg_replace('/\?=(?==\?)/', '?= ', $s);
            $s = preg_replace_callback('/=\?[^?\s]+\?[BbQq]\?[^?]*\?=/', fn ($w) => preg_replace('/\s+/', '', $w[0]), $s);
            $decoded = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                $s = $decoded;
            }
        }

        return self::fix($s);
    }

    /**
     * Имя вложения из сырых заголовков части письма. Библиотека IMAP ломается на именах из нескольких
     * encoded-word (Outlook: «=?koi8-r?B?…?= =?koi8-r?Q?.XLSX?=») и на RFC 2231 (filename*0*=…), поэтому разбираем сами.
     */
    public static function attachmentName(string $rawHeaders): ?string
    {
        $h = preg_replace("/\r?\n[ \t]+/", ' ', $rawHeaders);
        foreach (['filename', 'name'] as $param) {
            // RFC 2231: filename*=utf-8''%D0%A1%D1%87%D0%B5%D1%82.pdf или по кускам filename*0*=…; filename*1*=…
            if (preg_match_all('/[;\s]' . $param . '\*(\d*)\*?=\s*("([^"]*)"|[^;\s]+)/i', $h, $mm, PREG_SET_ORDER)) {
                $pieces = [];
                foreach ($mm as $m) {
                    $pieces[(int) $m[1]] = $m[3] !== '' ? $m[3] : $m[2];
                }
                ksort($pieces);
                $joined = implode('', $pieces);
                $charset = 'UTF-8';
                if (preg_match("/^([^']*)'[^']*'(.*)$/s", $joined, $cm)) {
                    $charset = $cm[1] ?: 'UTF-8';
                    $joined = $cm[2];
                }
                $decoded = rawurldecode($joined);
                if (strcasecmp($charset, 'UTF-8') !== 0) {
                    $decoded = @mb_convert_encoding($decoded, 'UTF-8', $charset) ?: $decoded;
                }
                if (trim($decoded) !== '') {
                    return self::fix(trim($decoded));
                }
            }
            if (preg_match('/[;\s]' . $param . '=\s*("((?:[^"\\\\]|\\\\.)*)"|[^;\s]+)/i', $h, $m)) {
                $v = isset($m[2]) && $m[2] !== '' ? stripslashes($m[2]) : $m[1];
                if (str_contains($v, '=?')) {
                    // соседние encoded-word разделены пробелом — iconv их склеит сам, лишь бы внутри слова не было пробелов
                    $v = preg_replace_callback('/=\?[^?\s]+\?[BbQq]\?[^?]*\?=/', fn ($w) => preg_replace('/\s+/', '', $w[0]), $v);
                    $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
                    if (is_string($d) && trim($d) !== '') {
                        $v = $d;
                    }
                }
                if (trim($v) !== '') {
                    return self::fix(trim($v));
                }
            }
        }

        return null;
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
