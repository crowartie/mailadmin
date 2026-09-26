<?php

namespace App\Services\Mail;

use Symfony\Component\Mime\Address;

/**
 * Разбор строки адресатов: «Имя <адрес>, адрес2; адрес3».
 *
 * Чистая работа с текстом — ни соединения, ни состояния, — и именно поэтому её надо уметь
 * проверять отдельно. Кавычки, скобки и запятые внутри имени ломают наивное деление по
 * запятой, а потерянный получатель ничем себя не проявляет: письмо уходит остальным,
 * и человек узнаёт об этом от того, кто письма не получил.
 */
class MailAddressList
{

    /**
     * Разобрать «Имя <адрес>, адрес2; адрес3».
     *
     * $lenient — для черновика: неверный адрес пропускается, а не обрывает сохранение. Раньше одна
     * опечатка в «Кому» не давала сохранить черновик вовсе (по журналу — по 7–9 неудач подряд
     * каждые 30 с), и написанное жило только в открытой вкладке.
     */
    public static function parseAddresses(string $raw, bool $lenient = false): array
    {
        $out = [];
        foreach (self::splitAddresses($raw) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            if (preg_match('/^(.*?)\s*<([^<>]+)>\s*$/su', $piece, $m)) {
                $mail = trim($m[2]);
                $name = trim($m[1]);
                // Имя в кавычках («"Иванов, Иван"», «"\"Фирма\" - Иванов"») — снять кавычки и экранирование;
                // имя с незакрытой кавычкой («"Фирма" - Иванов») — оставить как есть, Symfony сам закавычит при отправке.
                if (preg_match('/^"(.*)"$/su', $name, $q)) {
                    $name = str_replace(['\\"', '\\\\'], ['"', '\\'], $q[1]);
                }
            } else {
                $mail = trim($piece, " \t\"'<>");
                $name = '';
            }
            // Домен на кириллице («почта.рф») записываем в почтовый формат: фишка в окне письма
            // такой адрес принимала, а отправка потом отказывала непонятной ошибкой.
            $ascii = $mail;
            if (function_exists('idn_to_ascii') && preg_match('/^(.+)@([^@]+)$/u', $mail, $mm)) {
                $host = @idn_to_ascii($mm[2], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if (is_string($host) && $host !== '') {
                    $ascii = $mm[1] . '@' . $host;
                }
            }
            if (! filter_var($ascii, FILTER_VALIDATE_EMAIL)) {
                if ($lenient) {
                    continue;
                }
                abort(422, "Неверный адрес: {$piece}");
            }
            $out[] = new Address($ascii, $name);
        }

        return $out;
    }


    /** Делим список адресов по запятым и точкам с запятой, не трогая те, что внутри кавычек и угловых скобок. */
    public static function splitAddresses(string $raw): array
    {
        $parts = [];
        $cur = '';
        $quoted = false;
        $angle = 0;
        $prev = '';
        foreach (preg_split('//u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if ($ch === '"' && $prev !== '\\') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $ch === '<') {
                $angle++;
            } elseif (! $quoted && $ch === '>') {
                $angle = max(0, $angle - 1);
            } elseif (($ch === ',' || $ch === ';' || $ch === "\n") && ! $quoted && $angle === 0) {
                $parts[] = $cur;
                $cur = '';
                $prev = $ch;
                continue;
            }
            $cur .= $ch;
            $prev = $ch;
        }
        $parts[] = $cur;
        if ($quoted && count($parts) === 1 && preg_match_all('/<[^<>]+>/', $raw) > 1) {
            // Незакрытая кавычка «съела» остальные адреса — делим грубо, по запятым вне скобок.
            return preg_split('/[;,\n]+(?![^<]*>)/u', $raw) ?: [$raw];
        }

        return $parts;
    }
}
