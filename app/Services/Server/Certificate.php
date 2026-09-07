<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\Cache;

/** Сертификат почтового сервера (/etc/ssl/certs/iRedMail.crt → Let's Encrypt): имена, сроки, продление. */
class Certificate
{
    /** @return array{subject:string,issuer:string,names:array<int,string>,from:string,to:string,daysLeft:int,renewAt:string,letsEncrypt:bool}|null */
    public function info(): ?array
    {
        return Cache::remember('cert.info', 300, function () {
            // Файл — ссылка в /etc/letsencrypt (только root), поэтому читаем через обёртку.
            [$code, $out] = Ctl::run('cert-info', [], 10);
            if ($code !== 0 || ! preg_match('/notAfter=(.+)/', $out, $m)) {
                return null;
            }
            $to = strtotime(trim($m[1]));
            $from = preg_match('/notBefore=(.+)/', $out, $f) ? strtotime(trim($f[1])) : time();
            $issuer = '';
            if (preg_match('/^issuer=(.*)$/m', $out, $i)) {
                $issuer = preg_match('/O\s*=\s*([^,]+)/', $i[1], $o) ? trim($o[1]) : (preg_match('/CN\s*=\s*([^,]+)/', $i[1], $o) ? trim($o[1]) : trim($i[1]));
            }
            $subject = preg_match('/^subject=.*?CN\s*=\s*([^,]+)/m', $out, $c) ? trim($c[1]) : '';
            $names = preg_match_all('/DNS:([^,\s]+)/', $out, $n) ? $n[1] : [];

            return [
                'subject' => $subject,
                'issuer' => $issuer,
                'names' => $names ?: [$subject],
                'from' => date('Y-m-d', $from),
                'to' => date('Y-m-d', $to),
                'daysLeft' => (int) floor(($to - time()) / 86400),
                'renewAt' => date('j.m.Y', $to - 30 * 86400),
                'letsEncrypt' => str_contains($issuer, "Let's Encrypt"),
            ];
        });
    }

    /** @return array{ok:bool,output:string} */
    public function renew(): array
    {
        [$code, $out, $err] = Ctl::run('cert-renew', [], 180);
        Cache::forget('cert.info');

        return ['ok' => $code === 0 && ! str_contains($out, 'rc=1'), 'output' => trim($out . "\n" . $err)];
    }

    public function lastAttempt(): ?string
    {
        [$code, $out] = Ctl::run('cert-log', [], 10);
        if ($code !== 0) {
            return null;
        }
        $lines = array_values(array_filter(preg_split('/\r?\n/', $out), fn ($l) => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $l)));
        $last = end($lines);
        if (! $last) {
            return null;
        }
        $when = substr($last, 0, 16);
        $what = str_contains($out, 'not yet due') || str_contains($out, 'not due for renewal') ? 'продлевать рано' : (str_contains($out, 'error') || str_contains($out, 'Failed') ? 'ошибка — смотрите журнал' : 'выполнено');

        return $when . ' · ' . $what;
    }
}
