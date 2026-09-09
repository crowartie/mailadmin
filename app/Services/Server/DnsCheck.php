<?php

namespace App\Services\Server;

/**
 * Проверка DNS почтового домена: что должно быть и что есть на самом деле.
 * Спрашиваем ПУБЛИЧНЫЕ резолверы (dig @8.8.8.8 / @1.1.1.1) — так же, как чужие серверы.
 * Через локальный systemd-resolved нельзя: для собственного имени машины он отдаёт адрес из /etc/hosts
 * (192.168.x.x), и проверка «ожидает» локальный IP вместо внешнего.
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
        $rows[] = ['name' => 'DMARC _dmarc', 'expected' => 'v=DMARC1; p=quarantine; rua=mailto:' . $this->reportsMailbox($domain), 'actual' => $dmarc[0] ?? 'нет записи', 'kind' => $dmarc ? (preg_match('/p=(quarantine|reject)/', $dmarc[0]) ? 'ok' : 'warn') : 'no', 'note' => $dmarc ? (preg_match('/p=(quarantine|reject)/', $dmarc[0]) ? 'совпадает' : 'политика none — только отчёты') : 'нет политики'];

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

        $rpt = array_values(array_filter($this->txt('_smtp._tls.' . $domain), fn ($t) => str_starts_with($t, 'v=TLSRPTv1')));
        $rows[] = ['name' => 'TLS-RPT _smtp._tls', 'expected' => 'v=TLSRPTv1; rua=mailto:' . $this->reportsMailbox($domain), 'actual' => $rpt[0] ?? 'нет записи', 'kind' => $rpt ? 'ok' : 'warn', 'note' => $rpt ? 'отчёты о TLS будут приходить' : 'необязательно: отчёты о сбоях TLS от Google и др.'];
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
        $names = $this->dig('-x ' . $ip, 'PTR');
        $actual = $names ? rtrim($names[0], '.') : '';
        $ok = $actual && strcasecmp($actual, $mailHost) === 0;

        return ['ip' => $ip, 'actual' => $actual ?: 'нет записи', 'kind' => $ok ? 'ok' : 'no', 'note' => $ok ? 'совпадает' : 'нужен ' . $mailHost . ' — заявка провайдеру интернета'];
    }

    /** Внешний адрес сервера — то, что видят чужие серверы по имени mail-хоста. */
    public function externalIp(string $mailHost): ?string
    {
        $a = $this->records($mailHost, DNS_A);
        foreach ($a as $r) {
            if (filter_var($r['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $r['ip'];
            }
        }

        return $a[0]['ip'] ?? null;
    }

    /** Публичные резолверы по очереди; если dig недоступен или все молчат — системный резолвер. */
    private const RESOLVERS = ['8.8.8.8', '1.1.1.1', '77.88.8.8'];

    /** @return string[] строки вывода `dig +short` (пусто — записи нет или резолвер молчит) */
    private function dig(string $name, string $type): array
    {
        static $dead = [];
        foreach (self::RESOLVERS as $ns) {
            if (isset($dead[$ns])) {
                continue;
            }
            $args = array_merge(['dig', '+short', '+time=2', '+tries=1', '@' . $ns], preg_split('/\s+/', trim($name)), [$type]);
            $p = new \Symfony\Component\Process\Process($args);
            $p->setTimeout(6);
            try {
                $p->run();
            } catch (\Throwable) {
                $dead[$ns] = true;
                continue;
            }
            $out = trim($p->getOutput());
            if ($p->getExitCode() !== 0 || str_contains($out, 'communications error') || str_contains($out, 'timed out')) {
                $dead[$ns] = true;
                continue;
            }

            return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $out)), fn ($l) => $l !== '' && ! str_starts_with($l, ';')));
        }

        // Все публичные резолверы недоступны (например, закрыт исходящий 53-й) — спрашиваем системный.
        return $this->digSystem($name, $type);
    }

    /** @return string[] */
    private function digSystem(string $name, string $type): array
    {
        $map = ['A' => DNS_A, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'CNAME' => DNS_CNAME];
        if (str_starts_with($name, '-x ')) {
            $h = @gethostbyaddr(substr($name, 3));

            return $h && $h !== substr($name, 3) ? [$h . '.'] : [];
        }
        $r = @dns_get_record($name, $map[$type] ?? DNS_A);
        $out = [];
        foreach (is_array($r) ? $r : [] as $rec) {
            $out[] = match ($type) {
                'A' => $rec['ip'] ?? '',
                'MX' => ($rec['pri'] ?? 0) . ' ' . ($rec['target'] ?? '') . '.',
                'CNAME' => ($rec['target'] ?? '') . '.',
                'TXT' => '"' . implode('', (array) ($rec['entries'] ?? [$rec['txt'] ?? ''])) . '"',
                default => '',
            };
        }

        return array_values(array_filter($out));
    }

    /** Совместимый с dns_get_record формат — остальной код читает поля ip/target/pri. @return array<int,array<string,mixed>> */
    private function records(string $host, int $type): array
    {
        $out = [];
        switch ($type) {
            case DNS_A:
                foreach ($this->dig($host, 'A') as $l) {
                    if (filter_var($l, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $out[] = ['ip' => $l];
                    }
                }
                break;
            case DNS_MX:
                foreach ($this->dig($host, 'MX') as $l) {
                    if (preg_match('/^(\d+)\s+(\S+)$/', $l, $m)) {
                        $out[] = ['pri' => (int) $m[1], 'target' => rtrim($m[2], '.')];
                    }
                }
                usort($out, fn ($a, $b) => $a['pri'] <=> $b['pri']);
                break;
            case DNS_CNAME:
                foreach ($this->dig($host, 'CNAME') as $l) {
                    if (preg_match('/^[a-z0-9.-]+\.$/i', $l)) {
                        $out[] = ['target' => rtrim($l, '.')];
                    }
                }
                break;
        }

        return $out;
    }

    /** @return string[] */
    private function txt(string $host): array
    {
        $out = [];
        foreach ($this->dig($host, 'TXT') as $l) {
            // dig печатает строку кусками в кавычках: "v=DKIM1; k=rsa; " "p=MIIB…"
            if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $l, $m)) {
                $out[] = str_replace('\\"', '"', implode('', $m[1]));
            }
        }

        return $out;
    }

    private function samePubkey(string $a, string $b): bool
    {
        $norm = fn ($s) => preg_match('/p=([A-Za-z0-9+\/=]+)/', str_replace([' ', '"'], '', $s), $m) ? $m[1] : null;

        return $norm($a) !== null && $norm($a) === $norm($b);
    }

    /** Куда приходят отчёты DMARC/TLS-RPT: ящик из настроек или postmaster. */
    private function reportsMailbox(string $domain): string
    {
        $m = (string) (\App\Models\AppSetting::group('reports')['mailbox'] ?? '');

        return $m !== '' ? $m : 'postmaster@' . $domain;
    }
}

