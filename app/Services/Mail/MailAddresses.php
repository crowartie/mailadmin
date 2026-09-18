<?php

namespace App\Services\Mail;

/**
 * Адреса письма в том виде, в каком их ждёт интерфейс.
 *
 * Чистая функция: ей не нужны ни соединение, ни папки, ни состояние. Раньше она жила
 * приватным методом внутри чтения письма, и из-за этого её нельзя было ни проверить
 * отдельно, ни позвать из сборки переписки, не таща за собой весь класс.
 */
class MailAddresses
{
    /**
     * @param  mixed  $attribute  поле письма (To, Cc, Bcc, Reply-To) от библиотеки
     * @return array<int,array<string,mixed>>
     */
    public static function of($attribute): array
    {
        $out = [];
        // Attribute — только ArrayAccess, не итератор: перебираем через toArray().
        foreach (($attribute ? $attribute->toArray() : []) as $a) {
            $out[] = Directory::fill(Mime::address($a->personal, $a->mail));
        }

        return $out;
    }
}
