<?php

namespace App\Services\Dav;

use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

/**
 * vCard ↔ массив для интерфейса. Храним vCard 3.0 — его понимают все клиенты.
 */
class Cards
{
    /** @return array<string,mixed> */
    public static function parse(string $vcf, string $uri = '', string $etag = ''): array
    {
        try {
            /** @var VCard $card */
            $card = Reader::read($vcf, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (\Throwable) {
            return ['uri' => $uri, 'etag' => $etag, 'fn' => '(повреждённая карточка)', 'emails' => [], 'phones' => [], 'addresses' => [], 'groups' => []];
        }

        $n = $card->N ? array_map('trim', $card->N->getParts()) : [];
        $fn = trim((string) $card->FN);
        if ($fn === '') {
            $fn = trim(implode(' ', array_filter([$n[1] ?? '', $n[2] ?? '', $n[0] ?? '']))) ?: (string) ($card->EMAIL ?? '');
        }

        $emails = [];
        foreach ($card->select('EMAIL') as $p) {
            $emails[] = ['value' => strtolower(trim((string) $p)), 'type' => self::type($p, 'work')];
        }
        $phones = [];
        foreach ($card->select('TEL') as $p) {
            $phones[] = ['value' => trim((string) $p), 'type' => self::type($p, 'work')];
        }
        $addresses = [];
        foreach ($card->select('ADR') as $p) {
            $parts = array_pad(array_map('trim', $p->getParts()), 7, '');
            $addresses[] = ['type' => self::type($p, 'work'), 'street' => $parts[2], 'city' => $parts[3], 'region' => $parts[4], 'postal' => $parts[5], 'country' => $parts[6]];
        }
        $groups = [];
        if ($card->CATEGORIES) {
            $groups = array_values(array_filter(array_map('trim', $card->CATEGORIES->getParts())));
        }
        $org = $card->ORG ? array_map('trim', $card->ORG->getParts()) : [];
        $photo = null;
        if ($card->PHOTO) {
            $p = $card->PHOTO;
            $val = (string) $p->getValue();
            if (isset($p['VALUE']) && strtolower((string) $p['VALUE']) === 'uri') {
                $photo = $val;
            } elseif ($val !== '') {
                $type = strtolower((string) ($p['TYPE'] ?? 'jpeg'));
                $photo = 'data:image/' . (str_contains($type, 'png') ? 'png' : 'jpeg') . ';base64,' . base64_encode($val);
            }
        }

        return [
            'uri' => $uri,
            'etag' => $etag,
            'uid' => (string) ($card->UID ?? ''),
            'fn' => $fn,
            'last' => $n[0] ?? '',
            'first' => $n[1] ?? '',
            'middle' => $n[2] ?? '',
            'nick' => (string) ($card->NICKNAME ?? ''),
            'org' => $org[0] ?? '',
            'department' => $org[1] ?? '',
            'title' => (string) ($card->TITLE ?? ''),
            'emails' => $emails,
            'phones' => $phones,
            'addresses' => $addresses,
            'birthday' => $card->BDAY ? substr((string) $card->BDAY, 0, 10) : '',
            'url' => (string) ($card->URL ?? ''),
            'note' => (string) ($card->NOTE ?? ''),
            'groups' => $groups,
            'favorite' => (string) ($card->{'X-FAVORITE'} ?? '') === '1',
            'photo' => $photo,
            'employee' => (string) ($card->{'X-EMPLOYEE'} ?? '') === '1',
            'email' => $emails[0]['value'] ?? '',
        ];
    }

    private static function type($prop, string $default): string
    {
        $types = array_map('strtolower', array_map('strval', iterator_to_array($prop['TYPE'] ?? new \ArrayIterator([]))));
        foreach (['work', 'home', 'cell', 'fax', 'other'] as $t) {
            if (in_array($t, $types, true)) {
                return $t;
            }
        }

        return $default;
    }

    /**
     * Собрать vCard из формы. При правке передаём старую карточку: сохраняем UID, фото и
     * незнакомые интерфейсу поля (KEY, X-…), чтобы правка из веб-почты не стирала данные телефона.
     *
     * @param  array<string,mixed>  $c
     */
    public static function build(array $c, ?string $existing = null): string
    {
        $card = null;
        if ($existing) {
            try {
                $card = Reader::read($existing, Reader::OPTION_FORGIVING);
            } catch (\Throwable) {
                $card = null;
            }
        }
        if (! $card instanceof VCard) {
            $card = new VCard(['VERSION' => '3.0', 'UID' => self::uid()]);
        }
        $card->VERSION = '3.0';
        if (! $card->UID) {
            $card->UID = self::uid();
        }
        $card->PRODID = '-//Почта ' . config('areas.default_domain') . '//RU';

        foreach (['FN', 'N', 'NICKNAME', 'ORG', 'TITLE', 'EMAIL', 'TEL', 'ADR', 'BDAY', 'URL', 'NOTE', 'CATEGORIES', 'X-FAVORITE', 'REV'] as $name) {
            $card->remove($name);
        }

        $first = trim((string) ($c['first'] ?? ''));
        $last = trim((string) ($c['last'] ?? ''));
        $middle = trim((string) ($c['middle'] ?? ''));
        $fn = trim((string) ($c['fn'] ?? ''));
        if ($fn === '') {
            $fn = trim(implode(' ', array_filter([$last, $first, $middle]))) ?: trim((string) ($c['org'] ?? '')) ?: (string) ($c['emails'][0]['value'] ?? 'Без имени');
        }
        $card->FN = $fn;
        $card->add('N', [$last, $first, $middle, '', '']);
        if (filled($c['nick'] ?? null)) {
            $card->NICKNAME = trim($c['nick']);
        }
        if (filled($c['org'] ?? null) || filled($c['department'] ?? null)) {
            $card->add('ORG', [trim((string) ($c['org'] ?? '')), trim((string) ($c['department'] ?? ''))]);
        }
        if (filled($c['title'] ?? null)) {
            $card->TITLE = trim($c['title']);
        }
        foreach ((array) ($c['emails'] ?? []) as $i => $e) {
            $v = strtolower(trim((string) ($e['value'] ?? '')));
            if ($v !== '') {
                $params = ['TYPE' => [strtoupper($e['type'] ?? 'work'), 'INTERNET']];
                if ($i === 0) {
                    $params['TYPE'][] = 'PREF';
                }
                $card->add('EMAIL', $v, $params);
            }
        }
        foreach ((array) ($c['phones'] ?? []) as $p) {
            $v = trim((string) ($p['value'] ?? ''));
            if ($v !== '') {
                $t = strtoupper($p['type'] ?? 'work');
                $card->add('TEL', $v, ['TYPE' => $t === 'CELL' ? ['CELL', 'VOICE'] : [$t, 'VOICE']]);
            }
        }
        foreach ((array) ($c['addresses'] ?? []) as $a) {
            $parts = ['', '', trim((string) ($a['street'] ?? '')), trim((string) ($a['city'] ?? '')), trim((string) ($a['region'] ?? '')), trim((string) ($a['postal'] ?? '')), trim((string) ($a['country'] ?? ''))];
            if (implode('', $parts) !== '') {
                $card->add('ADR', $parts, ['TYPE' => strtoupper($a['type'] ?? 'work')]);
            }
        }
        if (filled($c['birthday'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['birthday'])) {
            $card->add('BDAY', $c['birthday'], ['VALUE' => 'DATE']);
        }
        if (filled($c['url'] ?? null)) {
            $card->URL = trim($c['url']);
        }
        if (filled($c['note'] ?? null)) {
            $card->NOTE = trim($c['note']);
        }
        $groups = array_values(array_unique(array_filter(array_map('trim', (array) ($c['groups'] ?? [])))));
        if ($groups) {
            $card->add('CATEGORIES', $groups);
        }
        if (! empty($c['favorite'])) {
            $card->add('X-FAVORITE', '1');
        }
        if (array_key_exists('photo', $c)) {
            $card->remove('PHOTO');
            if (is_string($c['photo']) && preg_match('~^data:image/(png|jpe?g);base64,(.+)$~s', $c['photo'], $m)) {
                $card->add('PHOTO', base64_decode($m[2]), ['ENCODING' => 'b', 'TYPE' => strtoupper($m[1] === 'png' ? 'PNG' : 'JPEG')]);
            }
        }
        $card->REV = gmdate('Ymd\THis\Z');

        return $card->serialize();
    }

    public static function uid(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    /** Разбить файл .vcf с несколькими карточками. @return string[] */
    public static function split(string $text): array
    {
        $out = [];
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        try {
            $splitter = new \Sabre\VObject\Splitter\VCard($stream, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
            while ($card = $splitter->getNext()) {
                $out[] = $card->serialize();
            }
        } catch (\Throwable) {
            // остаток файла не разобрался — отдаём то, что успели
        }
        fclose($stream);

        return $out;
    }
}
