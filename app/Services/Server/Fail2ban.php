<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\Cache;

/** fail2ban: кто заблокирован, разблокировать, заблокировать вручную, белый список. */
class Fail2ban
{
    private const JAIL_TITLES = ['sshd' => 'SSH', 'postfix' => 'SMTP · перебор паролей', 'dovecot' => 'IMAP/POP3 · перебор паролей', 'nginx-http-auth' => 'веб-почта · перебор паролей', 'pregreet' => 'SMTP · бот-спамер', 'sogo' => 'SOGo', 'postfix-sasl' => 'SMTP · перебор паролей'];

    /** @return array<int,array{ip:string,jail:string,why:string}>|null */
    public function banned(): ?array
    {
        [$code, $out] = Ctl::run('f2b-banned', [], 15);
        if ($code !== 0) {
            return null;
        }
        // Вывод — python-список словарей: [{'sshd': ['1.2.3.4']}, …]
        $json = str_replace("'", '"', trim($out));
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return [];
        }
        $rows = [];
        foreach ($data as $jailRow) {
            foreach ((array) $jailRow as $jail => $ips) {
                foreach ((array) $ips as $ip) {
                    $rows[] = ['ip' => $ip, 'jail' => $jail, 'why' => self::JAIL_TITLES[$jail] ?? $jail];
                }
            }
        }

        return $rows;
    }

    public function bannedCount(): ?int
    {
        return Cache::remember('f2b.count', 30, function () {
            $b = $this->banned();

            return $b === null ? null : count($b);
        });
    }

    /** @return array<int,array{jail:string,title:string,failed:int,banned:int,total:int}> */
    public function jails(): array
    {
        [$code, $out] = Ctl::run('f2b-status', [], 15);
        if ($code !== 0 || ! preg_match('/Jail list:\s*(.*)$/m', $out, $m)) {
            return [];
        }
        $rows = [];
        foreach (array_map('trim', explode(',', $m[1])) as $jail) {
            [$c, $o] = Ctl::run('f2b-jail', [$jail], 15);
            $rows[] = [
                'jail' => $jail,
                'title' => self::JAIL_TITLES[$jail] ?? $jail,
                'failed' => (int) (preg_match('/Currently failed:\s*(\d+)/', $o, $x) ? $x[1] : 0),
                'banned' => (int) (preg_match('/Currently banned:\s*(\d+)/', $o, $x) ? $x[1] : 0),
                'total' => (int) (preg_match('/Total banned:\s*(\d+)/', $o, $x) ? $x[1] : 0),
            ];
        }

        return $rows;
    }

    public function unban(string $ip): void
    {
        Ctl::out('f2b-unban', [$ip]);
        Cache::forget('f2b.count');
    }

    public function ban(string $ip, string $jail = 'postfix'): void
    {
        Ctl::out('f2b-ban', [$jail, $ip]);
        Cache::forget('f2b.count');
    }

    public function ignore(string $ip): void
    {
        Ctl::out('f2b-ignore', [$ip]);
    }

    /** Страна по IP — без внешних сервисов только грубо: локальная сеть или интернет. */
    public static function where(string $ip): string
    {
        return preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|127\.)/', $ip) ? 'локальная сеть' : 'интернет';
    }
}
