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
    /** Сколько писем спрашиваем одной командой (см. many). */
    private const CHUNK = 250;

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
     * Структуры сразу многих писем — одним запросом к серверу.
     * Нужно для поиска по имени вложения: имена лежат в структуре, а не в тексте.
     *
     * @param  int[]  $uids
     * @return array<int,array<int,array<string,mixed>>> uid → части
     */
    public static function many(Client $client, string $path, array $uids): array
    {
        if (! $uids) {
            return [];
        }
        $out = [];
        try {
            $client->openFolder($path, true);
            $conn = $client->getConnection();
            // Частями по двести пятьдесят: на первом запросе сервер собирает структуры
            // заново (дальше они у него в кэше), и одна команда на полторы тысячи писем
            // занимала двадцать секунд — на грани таймаута соединения, а по таймауту
            // поиск молча ответил бы «ничего не нашлось».
            foreach (array_chunk(array_map('intval', $uids), self::CHUNK) as $part) {
                $r = $conn->requestAndResponse('UID FETCH', [implode(',', $part), '(BODYSTRUCTURE)']);
                $rows = $r->getResponse();
                $flat = [];
                array_walk_recursive($rows, function ($x) use (&$flat) {
                    $flat[] = (string) $x;
                });
                // Ответ — несколько строк «* 37 FETCH (UID 2647 BODYSTRUCTURE (…))» подряд.
                foreach (preg_split('/(?=\*\s+\d+\s+FETCH\s+\()/', implode(' ', $flat)) ?: [] as $chunk) {
                    if (! preg_match('/UID\s+(\d+)/', $chunk, $m)) {
                        continue;
                    }
                    $parts = self::parse($chunk);
                    if ($parts !== null) {
                        $out[(int) $m[1]] = $parts;
                    }
                }
            }
        } catch (\Throwable) {
            return $out;   // что успели разобрать — уже польза
        }

        return $out;
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

        // Имя файла записывают по-разному: закодированным словом в «filename»/«name»
        // или по RFC 2231 в «filename*» — там впереди стоит кодировка, а сам текст
        // записан процентами («utf-8''%D0%9A…»).
        $name = self::name($dparams['filename'] ?? null)
            ?: self::name($params['name'] ?? null)
            ?: self::rfc2231($dparams['filename*'] ?? null)
            ?: self::rfc2231($params['name*'] ?? null);

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

    /** Имя файла из заголовка: оно приходит закодированным словом (=?UTF-8?B?…?=). */
    private static function name(?string $raw): string
    {
        return $raw === null || $raw === '' ? '' : trim((string) Charset::header($raw));
    }

    /** Имя по RFC 2231: «utf-8''%D0%9A%D0%B0…» — кодировка, язык и текст процентами. */
    private static function rfc2231(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $charset = 'UTF-8';
        $value = $raw;
        if (preg_match("/^([^']*)'[^']*'(.*)$/s", $raw, $m)) {
            $charset = $m[1] !== '' ? $m[1] : 'UTF-8';
            $value = $m[2];
        }
        $decoded = rawurldecode($value);

        return trim((string) Charset::body($decoded, strcasecmp($charset, 'UTF-8') === 0 ? '' : $charset));
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
     * Есть ли в письме то, что человек назовёт вложением.
     *
     * Правило одно на всё приложение: скрепка в списке, отбор «Вложения» и шапка
     * открытого письма должны отвечать одинаково. Раньше скрепка считалась по слову
     * «multipart/mixed» в заголовке и ошибалась на каждом десятом письме в обе стороны.
     *
     * @param  array<int,array<string,mixed>>  $parts
     */
    public static function hasFiles(array $parts): bool
    {
        return self::files($parts) !== [];
    }

    /**
     * Вложения без картинок, вставленных в текст письма.
     *
     * Логотип в подписи вложением не считается — иначе «есть:вложение» находило бы
     * каждое письмо с подписью. Но условие должно быть узким, иначе теряются настоящие
     * файлы: Foxmail проставляет Content-ID каждой части подряд, и письмо с тремя
     * чертежами по 400 КБ выглядело как письмо без вложений.
     *
     * Поэтому встроенной считается только часть, которая:
     *   • картинка (image/*), а не файл неизвестного вида;
     *   • не помечена отправителем как вложение — Content-Disposition: attachment
     *     ставят как раз тогда, когда картинку нужно и показать, и дать сохранить.
     *
     * @param  array<int,array<string,mixed>>  $parts
     * @return array<int,array<string,mixed>>
     */
    public static function files(array $parts): array
    {
        return array_values(array_filter(self::attachments($parts), fn (array $a) => ! self::isEmbeddedImage($a)));
    }

    /**
     * Часть письма — это картинка, вставленная в его текст?
     *
     * @param  array<string,mixed>  $part
     */
    public static function isEmbeddedImage(array $part): bool
    {
        return (string) $part['id'] !== ''
            && $part['disposition'] !== 'attachment'
            && str_starts_with(strtolower((string) $part['mime']), 'image/');
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
        try {
            $client->openFolder($path, true);
            $conn = $client->getConnection();
            // BODY.PEEK — «прочитать, не помечая прочитанным»: отметку ставим сами и осознанно.
            // Все нужные части просим одним запросом: письмо с двадцатью семью картинками
            // в тексте иначе делало двадцать семь обращений к серверу.
            $ask = implode(' ', array_map(fn (array $p) => 'BODY.PEEK[' . $p['no'] . ']', $parts));
            $r = $conn->requestAndResponse('UID FETCH', [(string) $uid, '(' . $ask . ')']);
            $rows = $r->getResponse();
            $flat = [];
            array_walk_recursive($rows, function ($x) use (&$flat) {
                $flat[] = (string) $x;
            });
            $joined = implode('', $flat);
        } catch (\Throwable) {
            return [];
        }

        $byNo = [];
        foreach ($parts as $p) {
            $byNo[(string) $p['no']] = (string) $p['encoding'];
        }

        // Ответ идёт подряд: «BODY[1.2] {2048}», перевод строки, ровно столько байтов,
        // потом следующая часть. Длину объявляет сам сервер — по ней и режем, не полагаясь
        // на то, где закончилась строка: хвост протокола прилипает к последней строке.
        $out = [];
        $pos = 0;
        while (preg_match('/BODY\[([\d.]+)\](?:<\d+>)?\s*\{(\d+)\}\r?\n/', $joined, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $no = (string) $m[1][0];
            $len = (int) $m[2][0];
            $from = (int) $m[0][1] + strlen((string) $m[0][0]);
            if (isset($byNo[$no])) {
                $out[$no] = self::decode(substr($joined, $from, $len), $byNo[$no]);
            }
            $pos = $from + $len;
        }
        if ($out) {
            return $out;
        }

        // Короткую часть сервер может прислать строкой в кавычках, без объявления длины.
        // Тогда в ответе она одна — её и возвращаем.
        $first = $parts[0];
        $tail = preg_replace('/^.*?BODY\[[\d.]+\](?:<\d+>)?\s*/s', '', $joined);
        $tail = preg_replace('/\)?\s*(TAG\d+\s+)?(OK|NO|BAD)\b.*$/s', '', (string) $tail);
        $tail = trim((string) $tail, "\"\r\n ");

        return $tail !== '' ? [(string) $first['no'] => self::decode($tail, (string) $first['encoding'])] : [];
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
