<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Webklex\PHPIMAP\Attachment;

/**
 * Разбор письма: адреса, имена вложений, идентификаторы, заголовки, критерии поиска.
 *
 * Вынесено из MailStore: это чистые функции без соединения с почтовым сервером,
 * их можно проверять тестами и звать откуда угодно.
 */
final class Mime
{
    /** Первый адрес из заголовка From/To: «Имя <адрес>», «"Имя" <адрес>», «=?…?= <адрес>» или просто адрес. */
    public static function firstAddress(string $header): ?array
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        // Первый адрес: до запятой, которая не внутри кавычек и не внутри <…>.
        $depth = 0; $quoted = false; $first = '';
        for ($i = 0, $n = strlen($header); $i < $n; $i++) {
            $c = $header[$i];
            if ($c === '"' && ($i === 0 || $header[$i - 1] !== '\\')) {
                $quoted = ! $quoted;
            } elseif (! $quoted && $c === '<') {
                $depth++;
            } elseif (! $quoted && $c === '>') {
                $depth = max(0, $depth - 1);
            } elseif (! $quoted && $depth === 0 && $c === ',') {
                break;
            }
            $first .= $c;
        }
        $first = trim($first);
        if (preg_match('/^(.*?)\s*<([^<>]*)>\s*$/s', $first, $m)) {
            return self::address(trim($m[1], " \t\"'"), trim($m[2]));
        }

        return self::address('', trim($first, " \t\"'"));
    }

    /**
     * Имя и адрес из разобранного библиотекой адреса. Outlook пишет «=?utf-8?B?…?=<user@host>» без пробела —
     * библиотека тогда считает адресом всю строку; вытаскиваем адрес и имя сами.
     *
     * @return array{name:string,mail:string}
     */
    public static function address(?string $personal, ?string $mail): array
    {
        $mail = trim((string) $mail);
        $name = trim((string) $personal);
        if ($mail !== '' && (str_contains($mail, '<') || str_contains($mail, '=?') || str_contains($mail, ' ') || ! str_contains($mail, '@'))) {
            $addr = preg_match('/<([^<>\s]+@[^<>\s]+)>/', $mail, $m) ? $m[1] : (preg_match('/[^\s<>"]+@[^\s<>"]+/', $mail, $m) ? $m[0] : $mail);
            $rest = trim(preg_replace('/<[^<>]*>/', '', str_replace($addr, '', $mail)), " \t\"'");
            if ($name === '' || $name === $mail) {
                $name = $rest;
            }
            $mail = $addr;
        }
        $name = trim((string) Charset::header($name), " \t\"'<>");

        return ['name' => $name !== '' ? $name : $mail, 'mail' => $mail];
    }

    /** Имя вложения: сначала из сырых заголовков части (библиотека ломается на koi8-r в две строки и RFC 2231), потом её версия. */
    public static function attachmentName(Attachment $a, string $fallback = 'attachment'): string
    {
        $raw = '';
        try {
            $part = (fn () => $this->part)->call($a);
            $raw = (string) ($part->getHeader()->raw ?? '');
        } catch (\Throwable) {
        }

        return ($raw !== '' ? Charset::attachmentName($raw) : null) ?: Charset::header($a->getName()) ?: $fallback;
    }

    /**
     * Message-ID из заголовка References/In-Reply-To в любом виде: «<a> <b>», «<a><b>», «a b», массив таких строк.
     * Возвращает голые идентификаторы без скобок и дублей.
     *
     * @return array<int,string>
     */
    public static function messageIds(array|string|null $raw): array
    {
        $joined = is_array($raw) ? implode(' ', array_map('strval', $raw)) : (string) $raw;
        $ids = [];
        if (preg_match_all('/<([^<>\s]+)>/', $joined, $m)) {
            $ids = $m[1];
        } else {
            $ids = preg_split('/\s+/', trim($joined), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $ids = array_map(fn ($id) => trim($id, " \t<>"), $ids);

        return array_values(array_unique(array_filter($ids, fn ($id) => $id !== '' && str_contains($id, '@'))));
    }

    /** Заголовки → [имя в нижнем регистре => значение] (первое вхождение, строки-продолжения склеены). */
    public static function parseHeaderFields(string $raw): array
    {
        $out = [];
        $raw = preg_replace("/\r?\n[ \t]+/", ' ', $raw) ?? $raw;
        foreach (preg_split("/\r?\n/", $raw) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/s', $line, $m)) {
                $name = strtolower($m[1]);
                $out[$name] ??= trim($m[2]);
            }
        }

        return $out;
    }

    /** Значение одного заголовка из сырого текста заголовков (строки-продолжения склеены); null, если заголовка нет. */
    public static function headerValue(string $rawHeaders, string $name): ?string
    {
        if ($rawHeaders === '') {
            return null;
        }
        $h = preg_replace("/
?
[ 	]+/", ' ', $rawHeaders) ?? $rawHeaders;

        return preg_match('/^' . preg_quote($name, '/') . ':[ 	]*(.*)$/mi', $h, $m) ? trim($m[1]) : null;
    }

    /** Имя папки из IMAP (modified UTF-7) → UTF-8. */
    public static function utf8Name(string $name): string
    {
        $d = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');

        return $d !== false && $d !== '' ? $d : $name;
    }

    /**
     * Время для сортировки цепочки. strtotime на непонятной дате возвращает false,
     * то есть ноль, и такое письмо всплывало в самое начало переписки.
     */
    public static function sortTime(?string $date): int
    {
        $t = $date ? strtotime($date) : false;

        return $t === false ? PHP_INT_MAX : $t;
    }

    /**
     * Критерии IMAP SEARCH «любое из»: OR в IMAP бинарный, поэтому N условий = N-1 вложенных OR.
     *
     * @param  array<int,array{0:string,1:string}>  $terms  [заголовок, значение]
     * @return string[]
     */
    public static function orCriteria(array $terms): array
    {
        $quote = fn (string $v) => '"' . addcslashes($v, '"\\') . '"';
        $parts = array_map(fn ($t) => ['HEADER', $t[0], $quote($t[1])], $terms);
        $out = array_pop($parts) ?? [];
        while ($parts) {
            $out = array_merge(['OR'], array_pop($parts), $out);
        }

        return $out;
    }

    /** Английский отказ почтового сервера — человеческим текстом. */
    public static function imapReason(string $reason): string
    {
        $r = strtolower($reason);
        if (str_contains($r, 'permission denied') || str_contains($r, 'read-only') || str_contains($r, 'readonly')) {
            return 'папка открыта только для просмотра';
        }
        if (str_contains($r, 'quota')) {
            return 'закончилось место в ящике';
        }
        if (str_contains($r, 'not found') || str_contains($r, 'nonexistent')) {
            return 'папки или письма больше нет';
        }

        return mb_substr($reason, 0, 120);
    }

    /**
     * Проверить ответ IMAP. Библиотека возвращает ответ и при NO/BAD, поэтому без этой проверки
     * отказ сервера («нет прав», «только для просмотра») выглядел как успешное действие.
     */
    public static function assertOk(mixed $response, string $what): void
    {
        $lines = [];
        try {
            $lines = is_object($response) && method_exists($response, 'getResponse') ? (array) $response->getResponse() : (array) $response;
        } catch (\Throwable) {
            return;
        }
        $flat = trim(implode(' ', array_map(fn ($x) => is_array($x) ? implode(' ', array_map('strval', $x)) : (string) $x, $lines)));
        if ($flat === '') {
            return;
        }
        if (preg_match('/(?:^|\s)(NO|BAD)\s+(.*)$/i', $flat, $m)) {
            $reason = trim($m[2]);
            throw MailException::denied($what . ($reason !== '' ? ': ' . self::imapReason($reason) : ''));
        }
    }
}
