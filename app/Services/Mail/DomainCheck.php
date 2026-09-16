<?php

namespace App\Services\Mail;

use App\Models\Vmail\Domain;
use App\Models\Webmail\Recent;
use Illuminate\Support\Facades\Cache;

/**
 * Проверка домена получателя до отправки: существует ли он, принимает ли почту, и не опечатка ли это
 * в известном домене (yndex.ru → yandex.ru). Postfix об этом узнал бы только после дней попыток доставки.
 */
class DomainCheck
{
    /** Домены, в которых чаще всего ошибаются. */
    private const POPULAR = [
        'yandex.ru', 'ya.ru', 'mail.ru', 'bk.ru', 'list.ru', 'inbox.ru', 'internet.ru', 'rambler.ru',
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'icloud.com',
        'protonmail.com', 'proton.me', 'vk.com',
    ];

    /**
     * @return array{status: 'ok'|'nodomain'|'nomail', text: ?string, suggestion: ?string}
     */
    public function check(string $domain, ?string $user = null): array
    {
        $domain = strtolower(trim($domain, " \t.\r\n"));
        if ($domain === '' || ! preg_match('/^[a-z0-9.-]+\.[a-z0-9-]{2,}$/i', $domain)) {
            return ['status' => 'ok', 'text' => null, 'suggestion' => null];
        }
        // Свои домены и домены сотрудников проверять нечего.
        if (Domain::query()->where('domain', $domain)->exists()) {
            return ['status' => 'ok', 'text' => null, 'suggestion' => null];
        }

        $status = Cache::remember('domaincheck:' . $domain, now()->addHours(6), fn () => $this->probe($domain));
        if ($status === 'ok') {
            return ['status' => 'ok', 'text' => null, 'suggestion' => null];
        }

        $suggestion = $this->suggest($domain, $user);
        $text = $status === 'nodomain'
            ? "Домена «{$domain}» не существует"
            : "Домен «{$domain}» не принимает почту";
        if ($suggestion) {
            $text .= ", возможно, имелся в виду {$suggestion}";
        }

        return ['status' => $status, 'text' => $text, 'suggestion' => $suggestion];
    }

    /** ok — принимает почту (или не удалось проверить), nodomain — записей нет, nomail — есть адрес, но порт 25 отказывает. */
    private function probe(string $domain): string
    {
        $ascii = function_exists('idn_to_ascii') ? (idn_to_ascii($domain) ?: $domain) : $domain;
        $hosts = [];
        foreach ((array) @dns_get_record($ascii, DNS_MX) as $r) {
            if (! empty($r['target'])) {
                $hosts[(int) ($r['pri'] ?? 0)][] = $r['target'];
            }
        }
        ksort($hosts);
        $hosts = array_merge(...array_values($hosts) ?: [[]]);
        if (! $hosts) {
            // Без MX почту доставляют на A-запись домена (RFC 5321) — как раз случай yndex.ru.
            $a = (array) @dns_get_record($ascii, DNS_A);
            if (! $a && ! @dns_get_record($ascii, DNS_AAAA)) {
                return 'nodomain';
            }
            $hosts = [$ascii];
        }

        $refused = 0;
        foreach (array_slice($hosts, 0, 2) as $host) {
            $errno = 0;
            $errstr = '';
            $s = @stream_socket_client('tcp://' . $host . ':25', $errno, $errstr, 3);
            if ($s) {
                fclose($s);

                return 'ok';
            }
            // Только явный отказ считаем «не принимает»: тайм-аут может быть просто закрытым наружу портом или медленной сетью.
            if ($errno === 111 || $errno === 10061 || str_contains($errstr, 'refused')) {
                $refused++;
            }
        }

        return $refused > 0 && $refused === count(array_slice($hosts, 0, 2)) ? 'nomail' : 'ok';
    }

    private function suggest(string $domain, ?string $user): ?string
    {
        $known = self::POPULAR;
        foreach (Domain::query()->pluck('domain') as $d) {
            $known[] = strtolower($d);
        }
        if ($user) {
            // Домены, куда пользователь уже писал не раз: опечатка в домене заказчика тоже ловится.
            foreach (Recent::query()->where('user', $user)->where('uses', '>=', 2)->pluck('email') as $e) {
                $d = strtolower((string) (explode('@', $e)[1] ?? ''));
                if ($d !== '') {
                    $known[] = $d;
                }
            }
        }
        $best = null;
        $bestDist = 3;
        foreach (array_unique($known) as $k) {
            if ($k === $domain) {
                return null;
            }
            $dist = levenshtein($domain, $k);
            if ($dist < $bestDist && $dist <= (strlen($k) >= 8 ? 2 : 1)) {
                $best = $k;
                $bestDist = $dist;
            }
        }

        return $best;
    }
}
