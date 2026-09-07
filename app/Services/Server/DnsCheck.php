<?php

namespace App\Services\Server;

/**
 * Проверка DNS почтового домена: что должно быть и что есть на самом деле.
 * Спрашиваем публичные резолверы системы (dns_get_record) — так же, как чужие серверы.
 */
class DnsCheck
{
    /** @return array<int,array{name:string,expected:string,actual:string,kind:string,note:string}> */
    public function check(string $domain, string $mailHost, ?string $dkimTxt = null, string $selector = 'dkim'): array
    {
        $ip = $this->externalIp($mailHost);
        $rows = [];

        $mx = $this->records($domain, DNS_MX);
        $mxHosts = array_map(fn ($r) => rtrim($r['target'], '.') . ' (' . $r['pri'] . ')', $mx);
        $mxOk = (bool) array_filter($mx, fn ($r) => strcasecmp(rtrim($r['target'], '.'), $mailHost) === 0);
        $rows[] = ['name' => 'MX', 'expected' => $mailHost . ', приоритет 10', 'actual' => $mxHosts ? implode(', ', $mxHosts) : 'нет записи', 'kind' => $mxOk ? 'ok' : 'no', 'note' => $mxOk ? 'совпадает' : 'письма извне не придут'];

        foreach (['mail', 'imap', 'smtp', 'webmail'] as $sub) {
            $host = $sub . '.' . $domain;
            $a = $this->records($host, DNS_A);
            $ips = array_map(fn ($r) => $r['ip'], $a);
            $ok = $ip && in_array($ip, $ips, true);
            $rows[] = ['name' => 'A ' . $sub, 'expected' => $ip ?: '?', 'actual' => $ips ? implode(', ', $ips) : 'нет записи', 'kind' => $ok ? 'ok' : ($ips ? 'warn' : 'no'), 'note' => $ok ? 'совпадает' : ($ips ? 'указывает не сюда' : ($sub === 'webmail' ? 'веб-почта по этому имени не откроется' : 'нужна для клиентов')), 'ok' => $ok];
        }

        $txt = $this->txt($domain);
        $spf = array_values(array_filter($txt, fn ($t) => str_starts_with($t, 'v=spf1')));
        $spfOk = $spf && (str_contains($spf[0], 'mx') || ($ip && str_contains($spf[0], $ip)));
        $rows[] = ['name' => 'SPF', 'expected' => 'v=spf1 mx -all', 'actual' => $spf[0] ?? 'нет записи', 'kind' => $spfOk ? ($spf && str_contains($spf[0], '-all') ? 'ok' : 'warn') : 'no', 'note' => $spfOk ? ($spf && str_contains($spf[0], '-all') ? 'совпадает' : 'мягкая политика ~all — лучше -all') : 'чужие серверы будут считать письма подозрительными'];

        $dkimHost = $selector . '._domainkey.' . $domain;
        $dkim = $this->txt($dkimHost);
        $dkimRec = $dkim ? implode('', $dkim) : '';
        $dkimOk = $dkimTxt ? $this->samePubkey($dkimRec, $dkimTxt) : str_contains($dkimRec, 'v=DKIM1');
        $rows[] = ['name' => 'DKIM ' . $selector . '._domainkey', 'expected' => $dkimTxt ? mb_substr($dkimTxt, 0, 40) . '…' : 'v=DKIM1; k=rsa; p=…', 'actual' => $dkimRec ? mb_substr($dkimRec, 0, 40) . '…' : 'нет записи', 'kind' => $dkimOk ? 'ok' : ($dkimRec ? 'no' : 'no'), 'note' => $dkimOk ? 'совпадает с ключом сервера' : ($dkimRec ? 'ключ в DNS не совпадает с ключом сервера' : 'подпись писем не проверяется')];

        $dmarc = array_values(array_filter($this->txt('_dmarc.' . $domain), fn ($t) => str_starts_with($t, 'v=DMARC1')));
        $rows[] = ['name' => 'DMARC _dmarc', 'expected' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@' . $domain, 'actual' => $dmarc[0] ?? 'нет записи', 'kind' => $dmarc ? (preg_match('/p=(quarantine|reject)/', $dmarc[0]) ? 'ok' : 'warn') : 'no', 'note' => $dmarc ? (preg_match('/p=(quarantine|reject)/', $dmarc[0]) ? 'совпадает' : 'политика none — только отчёты') : 'нет политики'];

        $ptr = $this->ptr($domain, $ip, $mailHost);
        if ($ptr) {
            $rows[] = ['name' => 'PTR ' . $ptr['ip'], 'expected' => $mailHost, 'actual' => $ptr['actual'], 'kind' => $ptr['kind'], 'note' => $ptr['note']];
        }

        foreach (['autoconfig', 'autodiscover'] as $sub) {
            $host = $sub . '.' . $domain;
            $c = $this->records($host, DNS_CNAME);
            $a = $this->records($host, DNS_A);
            $ok = ($c && strcasecmp(rtrim($c[0]['target'], '.'), $mailHost) === 0) || ($a && $ip && in_array($ip, array_map(fn ($r) => $r['ip'], $a), true));
            $rows[] = ['name' => $sub, 'expected' => 'CNAME → ' . $mailHost, 'actual' => $c ? 'CNAME → ' . rtrim($c[0]['target'], '.') : ($a ? 'A ' . $a[0]['ip'] : 'нет записи'), 'kind' => $ok ? 'ok' : 'warn', 'note' => $ok ? 'совпадает' : 'автонастройка телефонов и Outlook не сработает'];
        }

        $sts = $this->txt('_mta-sts.' . $domain);
        $rows[] = ['name' => 'MTA-STS', 'expected' => 'v=STSv1; id=…', 'actual' => $sts[0] ?? 'не настроено', 'kind' => $sts ? 'ok' : 'warn', 'note' => $sts ? 'настроено' : 'необязательно: защита от подмены TLS'];

        return $rows;
    }

    /** @return array{ip:string,actual:string,kind:string,note:string}|null */
    public function ptr(string $domain, ?string $ip = null, ?string $mailHost = null): ?array
    {
        $mailHost ??= 'mail.' . $domain;
        $ip ??= $this->externalIp($mailHost);
        if (! $ip) {
            return null;
        }
        $host = @gethostbyaddr($ip);
        $actual = $host && $host !== $ip ? $host : '';
        $ok = $actual && strcasecmp(rtrim($actual, '.'), $mailHost) === 0;

        return ['ip' => $ip, 'actual' => $actual ?: 'нет записи', 'kind' => $ok ? 'ok' : 'no', 'note' => $ok ? 'совпадает' : 'нужен ' . $mailHost . ' — заявка провайдеру интернета'];
    }

    public function externalIp(string $mailHost): ?string
    {
        $a = $this->records($mailHost, DNS_A);

        return $a[0]['ip'] ?? null;
    }

    /** @return array<int,array<string,mixed>> */
    private function records(string $host, int $type): array
    {
        $r = @dns_get_record($host, $type);

        return is_array($r) ? $r : [];
    }

    /** @return string[] */
    private function txt(string $host): array
    {
        $out = [];
        foreach ($this->records($host, DNS_TXT) as $r) {
            $out[] = implode('', (array) ($r['entries'] ?? [$r['txt'] ?? '']));
        }

        return $out;
    }

    private function samePubkey(string $a, string $b): bool
    {
        $norm = fn ($s) => preg_match('/p=([A-Za-z0-9+\/=]+)/', str_replace([' ', '"'], '', $s), $m) ? $m[1] : null;

        return $norm($a) !== null && $norm($a) === $norm($b);
    }
}
