<?php

namespace App\Services\Server;

use App\Models\ExternalSender;
use Illuminate\Support\Facades\Cache;

/**
 * Отправка от адресов нашего домена с чужих серверов (mail.ru, Яндекс, Gmail) без входа на наш SMTP.
 *
 * По умолчанию такие письма отклоняются как подделка (iRedAPD reject_sender_login_mismatch). Исключение —
 * пара «адрес + сервис»: Postfix пропускает письмо, только если отправитель в списке и сервер-источник входит
 * в SPF-диапазоны этого сервиса (карты /etc/postfix/mailadmin/, ставит mailadmin-ctl external-senders).
 * Пропуск — permit_auth_destination: только для наших получателей, чужим доменам через нас не уйдёт.
 */
class ExternalSenders
{
    public const PROVIDERS = [
        'mailru' => ['label' => 'Mail.ru (mail.ru, bk.ru, list.ru, inbox.ru)', 'spf' => ['mail.ru']],
        'yandex' => ['label' => 'Яндекс', 'spf' => ['yandex.ru']],
        'gmail' => ['label' => 'Google (Gmail)', 'spf' => ['gmail.com']],
        'any' => ['label' => 'Любой сервер (небезопасно)', 'spf' => []],
    ];

    public static function labels(): array
    {
        return array_map(fn ($p) => $p['label'], self::PROVIDERS);
    }

    /** Диапазоны серверов сервиса из его SPF (include/redirect раскрываются), кэш на сутки. */
    public function ranges(string $provider, bool $fresh = false): array
    {
        $domains = self::PROVIDERS[$provider]['spf'] ?? [];
        if (! $domains) {
            return [];
        }
        $key = 'external-senders.ranges.' . $provider;
        if (! $fresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }
        $out = [];
        foreach ($domains as $d) {
            $this->collect($d, $out, 0);
        }
        $out = array_values(array_unique($out));
        if ($out) {
            Cache::put($key, $out, 86400);
        }

        return $out;
    }

    /** Сколько диапазонов известно по каждому сервису (только из кэша, без запросов в DNS). */
    public static function cachedCounts(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $k => $p) {
            $out[$k] = $p['spf'] ? count((array) Cache::get('external-senders.ranges.' . $k, [])) : null;
        }

        return $out;
    }

    private function collect(string $domain, array &$out, int $depth): void
    {
        if ($depth > 8 || count($out) > 2000) {
            return;
        }
        foreach ((array) @dns_get_record($domain, DNS_TXT) as $r) {
            $txt = implode('', (array) ($r['entries'] ?? [$r['txt'] ?? '']));
            if (! str_starts_with(strtolower($txt), 'v=spf1')) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($txt)) as $term) {
                $term = ltrim($term, '+');
                if (preg_match('#^ip[46]:([0-9a-f.:/]+)$#i', $term, $m)) {
                    $out[] = $m[1];
                } elseif (preg_match('#^(?:include:|redirect=)([a-z0-9._-]+)$#i', $term, $m)) {
                    $this->collect($m[1], $out, $depth + 1);
                }
            }
        }
    }

    /** Собрать карты Postfix из базы и применить на сервере. */
    public function apply(bool $fresh = false): void
    {
        $dir = storage_path('app/private/postfix');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $rows = ExternalSender::query()->orderBy('address')->get();
        $map = "# Адреса, которым разрешено приходить с чужих серверов без входа. Файл создаёт веб-приложение.\n";
        foreach ($rows as $r) {
            $map .= $r->address . "\tmailadmin_ext_" . $r->provider . "\n";
        }
        file_put_contents($dir . '/external_senders', $map);
        $problems = [];
        foreach (self::PROVIDERS as $key => $p) {
            if (! $p['spf']) {
                continue;
            }
            $ranges = $this->ranges($key, $fresh);
            if (! $ranges && $rows->where('provider', $key)->isNotEmpty()) {
                $problems[] = $p['label'];
            }
            $cidr = '# Серверы «' . $p['label'] . "» по SPF. Файл создаёт веб-приложение.\n";
            foreach ($ranges as $c) {
                $cidr .= $c . "\tpermit_auth_destination\n";
            }
            file_put_contents($dir . '/ext_' . $key . '.cidr', $cidr);
        }
        Ctl::out('external-senders', [$dir]);
        if ($problems) {
            throw new \RuntimeException('Не удалось получить SPF-диапазоны: ' . implode(', ', $problems) . ' — письма с этих серверов пока не пройдут');
        }
    }
}
