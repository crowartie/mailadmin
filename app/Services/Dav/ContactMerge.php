<?php

namespace App\Services\Dav;

/**
 * Слияние дублей в общем списке контактов.
 *
 * Сотрудник из общей книги и его же карточка из «Моих контактов» (например, перенесённая с телефона:
 * только номер, имя в другом порядке) показывались двумя строками. Здесь личная карточка вливается
 * в карточку сотрудника: её телефоны, адреса, день рождения, заметка добавляются, а сама запись помечается
 * mergedInto — клиенты прячут её в общем списке, но показывают внутри своей книги (веб грузит все книги разом
 * и отбирает на месте). Совпадение — по адресу почты либо по имени (набор слов без учёта порядка и регистра: «Иванов Иван
 * Иванович» = «Иван Иванович Иванов»). В книге «Мои контакты» личная карточка остаётся как есть — слияние
 * только для списка без отбора по книге.
 *
 * Чистая функция: проверяется тестами без базы.
 */
final class ContactMerge
{
    /** @param array<int,array<string,mixed>> $cards  @return array<int,array<string,mixed>> */
    public static function merge(array $cards): array
    {
        $byMail = [];
        $byName = [];
        foreach ($cards as $i => $c) {
            if (empty($c['employee'])) {
                continue;
            }
            foreach ($c['emails'] ?? [] as $e) {
                $m = strtolower(trim((string) ($e['value'] ?? '')));
                if ($m !== '') {
                    $byMail[$m] ??= $i;
                }
            }
            $key = self::nameKey($c);
            if ($key !== '') {
                $byName[$key] ??= $i;
            }
        }
        if (! $byMail && ! $byName) {
            return $cards;
        }

        foreach ($cards as $i => $c) {
            if (! empty($c['employee']) || ! empty($c['readonly'])) {
                continue;   // сливаем только личные записи в карточку сотрудника
            }
            $target = null;
            foreach ($c['emails'] ?? [] as $e) {
                $m = strtolower(trim((string) ($e['value'] ?? '')));
                if ($m !== '' && isset($byMail[$m])) {
                    $target = $byMail[$m];
                    break;
                }
            }
            if ($target === null) {
                $key = self::nameKey($c);
                // По имени — только если в нём не меньше двух слов: «Иван» слился бы с любым Иваном.
                if ($key !== '' && substr_count($key, ' ') >= 1 && isset($byName[$key])) {
                    $target = $byName[$key];
                }
            }
            if ($target === null) {
                continue;
            }
            $cards[$target] = self::absorb($cards[$target], $c);
            $cards[$i]['mergedInto'] = (string) ($cards[$target]['uri'] ?? '');
        }

        return $cards;
    }

    /** Слова имени в нижнем регистре, отсортированные — порядок «Фамилия Имя» и «Имя Фамилия» равны. */
    public static function nameKey(array $c): string
    {
        $words = preg_split('/[\s,.]+/u', mb_strtolower(trim((string) ($c['fn'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn ($w) => mb_strlen($w) > 1 && ! str_contains($w, '@')));
        sort($words, SORT_STRING);

        return implode(' ', $words);
    }

    /** Добавить в карточку сотрудника то, чего в ней нет, из личной; источник помечаем для подписи. */
    private static function absorb(array $to, array $from): array
    {
        $label = (string) ($from['bookName'] ?? 'Мои контакты');
        foreach (['phones', 'emails'] as $f) {
            $have = array_map(fn ($x) => self::norm((string) ($x['value'] ?? '')), $to[$f] ?? []);
            foreach ($from[$f] ?? [] as $x) {
                $v = self::norm((string) ($x['value'] ?? ''));
                if ($v === '' || in_array($v, $have, true)) {
                    continue;
                }
                $to[$f][] = $x + ['source' => $label];
                $have[] = $v;
            }
        }
        if (empty($to['addresses']) && ! empty($from['addresses'])) {
            $to['addresses'] = $from['addresses'];
        }
        foreach (['birthday', 'url', 'note', 'nick', 'photo'] as $f) {
            if (empty($to[$f]) && ! empty($from[$f])) {
                $to[$f] = $from[$f];
            }
        }
        $to['favorite'] = ! empty($to['favorite']) || ! empty($from['favorite']);
        $to['merged'][] = ['book' => $from['book'] ?? '', 'uri' => $from['uri'] ?? '', 'bookName' => $label, 'fn' => (string) ($from['fn'] ?? '')];

        return $to;
    }

    private static function norm(string $v): string
    {
        $v = strtolower(trim($v));
        $digits = preg_replace('/\D/', '', $v) ?? '';
        if (strlen($digits) >= 10 && ! str_contains($v, '@')) {
            // телефон: 8902… и +7902… — один номер
            return substr($digits, -10);
        }

        return $v;
    }
}
