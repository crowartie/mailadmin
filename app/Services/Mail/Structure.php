<?php

namespace App\Services\Mail;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;

/**
 * Из чего состоит письмо — по одному запросу BODYSTRUCTURE, без скачивания самого письма.
 *
 * Зачем это нужно. Чтобы показать письмо, библиотека скачивает его целиком и тут же
 * раскодирует каждое вложение: письмо с семью мегабайтами файлов открывалось две с
 * половиной секунды и занимало полтораста мегабайт памяти — при пределе в 256 МБ
 * письмо на сорок мегабайт открыть уже нельзя. Сервер же умеет рассказать про части
 * письма отдельно: имена, типы и размеры вложений приходят за миллисекунды, а тело
 * потом дочитывается одной частью.
 *
 * Разбор ответа свой: библиотека разбивает эту строку на куски так, что вложенность
 * теряется.
 */
final class Structure
{
    /**
     * Части письма по UID. null — сервер ответил не так, как мы понимаем;
     * вызывающий в этом случае работает по-старому.
     *
     * @return array<int,array<string,mixed>>|null
     */
    public static function of(Client $client, string $path, int $uid): ?array
    {
        try {
            $client->openFolder($path, true);
            $conn = $client->getConnection();
            $r = $conn->requestAndResponse('UID FETCH', [(string) $uid, '(BODYSTRUCTURE)']);
            // Библиотека отдаёт ответ разобранным на куски, но с сохранёнными кавычками —
            // склеиваем обратно в строку протокола и разбираем сами.
            $resp = $r->getResponse();
            $flat = [];
            array_walk_recursive($resp, function ($x) use (&$flat) {
                $flat[] = (string) $x;
            });

            return self::parse(implode(' ', $flat));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Разобрать строку «* 1 FETCH (UID 2 BODYSTRUCTURE (…))».
     *
     * @return array<int,array<string,mixed>>|null
     */
    public static function parse(string $line): ?array
    {
        $at = stripos($line, 'BODYSTRUCTURE');
        if ($at === false) {
            return null;
        }
        $i = $at + strlen('BODYSTRUCTURE');
        while ($i < strlen($line) && $line[$i] === ' ') {
            $i++;
        }
        if (($line[$i] ?? '') !== '(') {
            return null;
        }
        $node = self::readList($line, $i);
        if (! is_array($node)) {
            return null;
        }
        $out = [];
        self::walk($node, '', $out);

        return $out ?: null;
    }

    /** Прочитать скобочный список протокола IMAP в вложенный массив. */
    private static function readList(string $s, int &$i): array|string|null
    {
        $n = strlen($s);
        if (($s[$i] ?? '') !== '(') {
            return self::readAtom($s, $i);
        }
        $i++;   // «(»
        $out = [];
        while ($i < $n) {
            $c = $s[$i];
            if ($c === ' ') {
                $i++;

                continue;
            }
            if ($c === ')') {
                $i++;
                break;
            }
            $out[] = $c === '(' ? self::readList($s, $i) : self::readAtom($s, $i);
        }

        return $out;
    }

    /** Строка в кавычках, литерал {N}, NIL или голое слово/число. */
    private static function readAtom(string $s, int &$i): string|null
    {
        $n = strlen($s);
        // Длинное или не-ASCII значение сервер присылает литералом: «{149}» и следом
        // ровно столько байтов. Так приходит, например, длинное имя файла — без разбора
        // литерала оно попадало в список вложений как «{149}».
        if (($s[$i] ?? '') === '{' && preg_match('/^\{(\d+)\}/', substr($s, $i, 12), $m)) {
            $len = (int) $m[1];
            $j = $i + strlen($m[0]);
            while ($j < $n && ($s[$j] === "" || $s[$j] === "
" || $s[$j] === ' ')) {
                $j++;
            }
            $i = min($n, $j + $len);

            return substr($s, $j, $len);
        }
        if (($s[$i] ?? '') === '"') {
            $i++;
            $buf = '';
            while ($i < $n) {
                $c = $s[$i];
                if ($c === '\\' && $i + 1 < $n) {
                    $buf .= $s[$i + 1];
                    $i += 2;

                    continue;
                }
                if ($c === '"') {
                    $i++;
                    break;
                }
                $buf .= $c;
                $i++;
            }

            return $buf;
        }
        $buf = '';
        while ($i < $n && $s[$i] !== ' ' && $s[$i] !== '(' && $s[$i] !== ')') {
            $buf .= $s[$i];
            $i++;
        }

        return strcasecmp($buf, 'NIL') === 0 ? null : $buf;
    }

    /**
     * Обойти дерево и пронумеровать части так же, как их нумерует сервер (RFC 3501, 7.4.2):
     * у составного письма части — «1», «2», у вложенного составного — «2.1», «2.2».
     *
     * @param  array<int,array<string,mixed>>  $out
     */
    private static function walk(array $node, string $prefix, array &$out): void
    {
        if (isset($node[0]) && is_array($node[0])) {
            // Составная часть: сначала вложенные части, потом её собственный подтип.
            $k = 0;
            foreach ($node as $child) {
                if (! is_array($child)) {
                    break;
                }
                $k++;
                self::walk($child, $prefix === '' ? (string) $k : $prefix . '.' . $k, $out);
            }

            return;
        }
        // Простая часть. Порядок полей задан протоколом.
        $type = strtolower((string) ($node[0] ?? ''));
        $subtype = strtolower((string) ($node[1] ?? ''));
        $params = self::pairs($node[2] ?? null);
        $id = trim((string) ($node[3] ?? ''), '<>');
        $encoding = strtolower((string) ($node[5] ?? ''));
        $size = (int) ($node[6] ?? 0);
        // Поля до седьмого у всех частей одинаковые, дальше — по-разному: у текстовой
        // части идёт число строк, у вложенного письма — конверт, структура и число строк,
        // и только потом общие поля (контрольная сумма, расположение, язык).
        $shift = $type === 'text' ? 1 : ($type === 'message' && $subtype === 'rfc822' ? 3 : 0);
        $disp = $node[8 + $shift] ?? null;
        $disposition = is_array($disp) ? strtolower((string) ($disp[0] ?? '')) : null;
        $dparams = is_array($disp) ? self::pairs($disp[1] ?? null) : [];

        $name = self::name($dparams['filename'] ?? null) ?: self::name($params['name'] ?? null);

        $out[] = [
            'no' => $prefix === '' ? '1' : $prefix,
            'type' => $type,
            'subtype' => $subtype,
            'mime' => $type . '/' . $subtype,
            'charset' => strtolower((string) ($params['charset'] ?? '')),
            'encoding' => $encoding,
            // Размер от сервера — это размер закодированной части. В base64 полезных
            // данных на четверть меньше, и к тому же каждые 76 знаков стоит перевод
            // строки: без его учёта размер выходил завышенным почти на три процента.
            'size' => $encoding === 'base64' ? (int) floor(($size - 2 * intdiv($size, 78)) * 3 / 4) : $size,
            'rawSize' => $size,
            'id' => $id,
            'disposition' => $disposition,
            'name' => $name,
        ];
    }

    /** («charset» «utf-8» «name» «файл.pdf») → ['charset' => 'utf-8', 'name' => 'файл.pdf'] */
    private static function pairs(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            $k = $list[$i];
            $v = $list[$i + 1];
            if (is_string($k)) {
                $out[strtolower($k)] = is_string($v) ? $v : null;
            }
        }

        return $out;
    }

    /** Имя файла из заголовка: оно приходит закодированным (=?UTF-8?B?…?=) или по RFC 2231. */
    private static function name(?string $raw): string
    {
        return $raw === null || $raw === '' ? '' : trim(Charset::header($raw));
    }

    /**
     * Вложения из разобранной структуры: всё, что не является телом письма.
     * Встроенные картинки (у них есть Content-ID и ссылка из текста) остаются в списке —
     * решение, показывать ли их отдельно, принимает вызывающий.
     *
     * @param  array<int,array<string,mixed>>  $parts
     * @return array<int,array<string,mixed>>
     */
    public static function attachments(array $parts): array
    {
        $bodies = self::bodyParts($parts);
        $skip = array_column($bodies, 'no');

        return array_values(array_filter($parts, function (array $p) use ($skip) {
            if (in_array($p['no'], $skip, true)) {
                return false;
            }
            // Многочастные обёртки в список не попадают (их здесь и нет), а вот
            // пустые технические части вроде application/pkcs7-signature — попадают.
            return ! ($p['type'] === 'text' && $p['name'] === '' && $p['disposition'] !== 'attachment');
        }));
    }

    /**
     * Части, составляющие само письмо: текст и/или HTML верхнего уровня.
     *
     * @param  array<int,array<string,mixed>>  $parts
     * @return array<int,array<string,mixed>>
     */
    public static function bodyParts(array $parts): array
    {
        $out = [];
        foreach ($parts as $p) {
            if ($p['type'] !== 'text' || ! in_array($p['subtype'], ['plain', 'html'], true)) {
                continue;
            }
            if ($p['disposition'] === 'attachment' || $p['name'] !== '') {
                continue;   // приложенный .txt — это вложение, а не текст письма
            }
            // Берём по одной части каждого вида — первую попавшуюся: это и есть тело.
            if (! isset($out[$p['subtype']])) {
                $out[$p['subtype']] = $p;
            }
        }

        return array_values($out);
    }

    /**
     * Скачать указанные части письма. Возвращает уже раскодированный текст по номеру части.
     *
     * @param  array<int,array<string,mixed>>  $parts
     * @return array<string,string>
     */
    public static function fetchParts(Client $client, string $path, int $uid, array $parts): array
    {
        if (! $parts) {
            return [];
        }
        $out = [];
        $client->openFolder($path, true);
        $conn = $client->getConnection();
        foreach ($parts as $p) {
            try {
                // BODY.PEEK — «прочитать, не помечая прочитанным»: отметку ставим сами и осознанно.
                $r = $conn->requestAndResponse('UID FETCH', [(string) $uid, '(BODY.PEEK[' . $p['no'] . '])']);
                $raw = self::payload($r->getResponse());
                if ($raw === null) {
                    continue;
                }
                $out[$p['no']] = self::decode($raw, (string) $p['encoding']);
            } catch (\Throwable) {
                // одна часть не пришла — остальные всё равно покажем
            }
        }

        return $out;
    }

    /** Достать из ответа тело части: библиотека кладёт его отдельным куском после «BODY[…]». */
    private static function payload(mixed $response): ?string
    {
        $rows = (array) $response;
        $flat = [];
        array_walk_recursive($rows, function ($x) use (&$flat) {
            $flat[] = (string) $x;
        });
        // Первая строка ответа приходит целиком: «* 5 FETCH (UID 7 BODY[1] {1234}»,
        // дальше кусками идёт сам текст, а в конце — закрывающая скобка и «OK …».
        $start = null;
        foreach ($flat as $k => $v) {
            if (str_contains($v, 'BODY[')) {
                $start = $k;
            }
        }
        if ($start === null) {
            return null;
        }
        $rest = array_slice($flat, $start + 1);
        // Иногда длина в фигурных скобках приходит отдельным куском — она нам не нужна.
        if (isset($rest[0]) && preg_match('/^\{\d+\}\s*$/', $rest[0])) {
            array_shift($rest);
        }
        while ($rest) {
            $tail = trim((string) end($rest));
            if ($tail === ')' || preg_match('/^(TAG\d+\s+)?(OK|NO|BAD)\b/i', $tail)) {
                array_pop($rest);

                continue;
            }
            break;
        }
        // Куски — это строки письма вместе с их переводами строк: склеиваем как есть,
        // иначе ломается quoted-printable, где перенос строки значим.
        return $rest ? implode('', $rest) : null;
    }

    /** Раскодировать часть по её Content-Transfer-Encoding. */
    public static function decode(string $raw, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $raw) ?? $raw, false),
            'quoted-printable' => (string) quoted_printable_decode($raw),
            default => $raw,
        };
    }
}
