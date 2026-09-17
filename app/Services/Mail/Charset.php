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
            // «utf8», «cp1251», «koi8r» — не имена кодировок для iconv, и такой заголовок
            // оставался на экране служебной записью вида =?utf8?B?…?=. Приводим к известным именам.
            $s = preg_replace_callback('/=\?([^?]+)\?([BbQq])\?/', fn ($m) => '=?' . self::charsetName($m[1]) . '?' . $m[2] . '?', $s);
            // Длинное имя файла разрезают на несколько encoded-word, и разрез приходится
            // посреди буквы: каждое слово раскрывается отдельно, и «АКБ.jpg» становилось
            // «АК?» плюс нерасшифрованный хвост. Соседние слова одной кодировки склеиваем.
            $glued = 1;
            while ($glued) {
                $s = preg_replace('/=\?([^?]+)\?([Qq])\?([^?]*)\?=\s*=\?\1\?\2\?([^?]*)\?=/', '=?$1?$2?$3$4?=', $s, 1, $glued) ?? $s;
            }
            $decoded = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                $s = $decoded;
            }
            // Если что-то осталось нераскрытым — разбираем сами: пользователю служебная запись не нужна.
            if (str_contains($s, '=?')) {
                $s = self::decodeWords($s);
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
                    // Без кавычек группа 3 отсутствует (filename*=utf-8''...) — раньше падало «Undefined array key 3».
                    $pieces[(int) $m[1]] = ($m[3] ?? '') !== '' ? $m[3] : $m[2];
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
                if (str_contains($decoded, '=?')) {
                    // РЖД и некоторые роботы режут encoded-word на куски filename*0=/filename*1= — после склейки его ещё надо раскодировать.
                    $d = @iconv_mime_decode(preg_replace('/\s+/', '', $decoded), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
                    if (is_string($d) && trim($d) !== '') {
                        $decoded = $d;
                    }
                }
                if (trim($decoded) !== '') {
                    return self::fix(trim($decoded));
                }
            }
            // Без кавычек берём всё до точки с запятой или конца строки: по RFC пробелов там быть
            // не должно, но их шлют, и «Счёт за май.pdf» сохранялся как «Счёт» — без расширения.
            if (preg_match('/[;\s]' . $param . '=\s*("((?:[^"\\\\]|\\\\.)*)"|[^;\r\n]+)/i', $h, $m)) {
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

    /** Привести написание кодировки к тому, что понимают iconv и mbstring. */
    private static function charsetName(string $name): string
    {
        $n = strtolower(trim($name));
        $n = preg_replace('/[^a-z0-9]/', '', $n) ?? $n;

        return match ($n) {
            'utf8' => 'UTF-8',
            'cp1251', 'win1251', 'windows1251', 'ansi1251' => 'Windows-1251',
            'cp1252', 'win1252', 'windows1252' => 'Windows-1252',
            'koi8r', 'koi8ru', 'koi8u' => 'KOI8-R',
            'cp866', 'ibm866', 'dos866' => 'CP866',
            'iso88591', 'latin1' => 'ISO-8859-1',
            'iso88595' => 'ISO-8859-5',
            default => $name,
        };
    }

    /** Раскрыть encoded-word вручную — когда iconv отказался (неизвестная кодировка, битый хвост). */
    private static function decodeWords(string $s): string
    {
        return (string) preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', function ($m) {
            $charset = self::charsetName($m[1]);
            $raw = strtoupper($m[2]) === 'B'
                ? (base64_decode($m[3], false) ?: '')
                : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            if ($raw === '') {
                return $m[0];
            }
            $out = @mb_convert_encoding($raw, 'UTF-8', $charset);

            return is_string($out) && $out !== '' ? $out : (self::fix($raw) ?? $m[0]);
        }, $s);
    }

    /**
     * Насколько строка похожа на осмысленный текст. Нужна, чтобы выбрать кодировку:
     * один и тот же набор байтов «читается» и как windows-1251, и как koi8-r,
     * но у неправильной таблицы получается набор редких букв и заглавных.
     */
    private static function score(string $utf): float
    {
        $len = mb_strlen($utf);
        if ($len === 0) {
            return -1000.0;
        }
        $good = preg_match_all('/[а-яёa-z0-9\s.,:;!?()\/@\-–—«»"\']/ui', $utf);
        $lower = preg_match_all('/[а-яё]/u', $utf);
        $upper = preg_match_all('/[А-ЯЁ]/u', $utf);
        // Управляющие, «нехорошие» служебные и символ-замена: признак неверной таблицы.
        $weird = preg_match_all('/[\x{0080}-\x{00BF}\x{0500}-\x{052F}\x{FFFD}]/u', $utf);

        // Русский текст почти весь строчный: koi8-r, прочитанный как windows-1251, даёт сплошные заглавные.
        return $good / $len - 2.0 * $weird / $len + 0.5 * ($lower + 1) / ($lower + $upper + 1);
    }

    public static function fix(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return $s;
        }

        if (! mb_check_encoding($s, 'UTF-8')) {
            // Сырые байты. Раньше всё, что не UTF-8, безусловно читалось как windows-1251,
            // и письма в koi8-r или западноевропейских кодировках превращались в кашу.
            // Перебираем таблицы и берём ту, после которой текст больше похож на текст.
            $best = null;
            $bestScore = -1000.0;
            // Непереводимые байты помечаем символом-заменой: по умолчанию mbstring ставит «?»,
            // а знак вопроса — обычная письменная пунктуация, и оценка считала мусор хорошим текстом.
            $prev = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            try {
                foreach (['UTF-8', 'Windows-1251', 'KOI8-R', 'CP866', 'ISO-8859-5', 'Windows-1252', 'ISO-8859-1'] as $table) {
                    $try = @mb_convert_encoding($s, 'UTF-8', $table);
                    if (! is_string($try) || $try === '' || ! mb_check_encoding($try, 'UTF-8')) {
                        continue;
                    }
                    $sc = self::score($try);
                    if ($sc > $bestScore) {
                        $bestScore = $sc;
                        $best = $try;
                    }
                }
            } finally {
                mb_substitute_character($prev);
            }

            return $best ?? mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
        }

        // Строка уже правильный UTF-8, но может быть «кракозяброй»: кириллица, прочитанная
        // однобайтовой таблицей. Раньше при одной-единственной настоящей кириллической букве
        // строка возвращалась как есть, и мусор в смешанной теме оставался на экране.
        if (! preg_match('/[\x{0080}-\x{024F}]{2}/u', $s)) {
            return $s;
        }

        // Чиним кусками, а не строку целиком: в смешанном письме («ÐžÑ‚Ñ‡Ñ‘Ñ‚ за сентябрь»)
        // обратный перевод всей строки уничтожает настоящую кириллицу, которая рядом.
        // Кракозябра — это подряд идущие знаки из «латинского» диапазона, обычными текстами
        // такие пары не встречаются.
        $run = '/[\x{0080}-\x{024F}\x{0192}\x{02C6}\x{2013}\x{2014}\x{2018}-\x{201E}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0152}\x{0153}]{2,}/u';
        $fixed = preg_replace_callback($run, function ($m) {
            foreach (['Windows-1252', 'ISO-8859-1', 'ISO-8859-2', 'ISO-8859-4', 'ISO-8859-10'] as $table) {
                $back = @mb_convert_encoding($m[0], $table, 'UTF-8');
                if (is_string($back) && $back !== '' && mb_check_encoding($back, 'UTF-8')
                    && preg_match('/[\x{0400}-\x{04FF}]/u', $back)) {
                    return $back;
                }
            }

            return $m[0];
        }, $s);

        return is_string($fixed) && $fixed !== '' ? $fixed : $s;
    }
}
