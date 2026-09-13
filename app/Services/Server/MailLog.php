<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\Cache;

/**
 * Журнал почты (/var/log/mail.log, формат rsyslog с ISO-временем): Postfix, Amavis, Dovecot.
 * Приложение стоит на одной машине с сервером, поэтому читает файл напрямую (группа adm).
 *
 * Даёт три вещи: ленту событий человеческим языком, «путь письма» по id/адресу
 * и статистику за сутки для обзора.
 */
class MailLog
{
    public const FILE = '/var/log/mail.log';

    private const LINE = '/^(\S+)\s+\S+\s+([\w\/.-]+?)(?:\[(\d+)\])?:\s+(.*)$/';

    /** @return array<int,array{time:string,prog:string,text:string,raw:string}> */
    public function lines(int $max = 20000, ?string $since = null): array
    {
        $files = [self::FILE . '.1', self::FILE];
        $out = [];
        foreach ($files as $f) {
            if (! is_readable($f)) {
                continue;
            }
            $h = fopen($f, 'r');
            // Только хвост большого файла: читаем с конца по 1 МБ, пока не наберём достаточно строк.
            if ($f === self::FILE && filesize($f) > 8_000_000 && $since === null) {
                fseek($h, -8_000_000, SEEK_END);
                fgets($h);
            }
            while (($line = fgets($h)) !== false) {
                if ($since !== null && strncmp($line, $since, strlen($since)) < 0) {
                    continue;
                }
                if (preg_match(self::LINE, rtrim($line), $m)) {
                    $out[] = ['time' => $m[1], 'prog' => $m[2], 'text' => $m[4], 'raw' => rtrim($line)];
                }
            }
            fclose($h);
        }

        return array_slice($out, -$max);
    }

    /** Строки, где встречается queue-id. @return array<int,array{time:string,prog:string,text:string,raw:string}> */
    public function linesFor(string $needle): array
    {
        return array_values(array_filter($this->lines(), fn ($l) => str_contains($l['raw'], $needle)));
    }

    // ── Лента событий ────────────────────────────────────────────────────

    /**
     * События последних N строк: kind = mail|spam|auth|error|admin, who, what.
     *
     * @return array<int,array<string,mixed>>
     */
    public function events(string $type = 'all', string $q = '', int $limit = 200, ?string $since = null): array
    {
        $lines = $this->lines(30000, $since);
        [$from, $msgid, $client] = $this->index($lines);
        $events = [];
        $q = mb_strtolower(trim($q));

        foreach ($lines as $l) {
            $e = $this->classify($l, $from, $msgid, $client);
            if (! $e) {
                continue;
            }
            if ($type !== 'all' && $e['kind'] !== $type) {
                continue;
            }
            if ($q !== '' && ! str_contains(mb_strtolower($e['who'] . ' ' . $e['what'] . ' ' . ($e['qid'] ?? '') . ' ' . ($e['msgid'] ?? '')), $q)) {
                continue;
            }
            $events[] = $e;
        }

        return array_slice(array_reverse($events), 0, $limit);
    }

    /** @return array{0:array<string,string>,1:array<string,string>,2:array<string,string>} qid→from, qid→message-id, qid→client */
    private function index(array $lines): array
    {
        $from = [];
        $msgid = [];
        $client = [];
        foreach ($lines as $l) {
            if (! preg_match('/^([A-Za-z0-9]{8,20}): (.*)$/', $l['text'], $m)) {
                continue;
            }
            [$qid, $rest] = [$m[1], $m[2]];
            if (str_starts_with($l['prog'], 'postfix/qmgr') && preg_match('/^from=<([^>]*)>/', $rest, $f)) {
                $from[$qid] = $f[1] !== '' ? $f[1] : 'MAILER-DAEMON';
            } elseif (str_starts_with($l['prog'], 'postfix/cleanup') && preg_match('/message-id=<?([^>\s]+)>?/', $rest, $f)) {
                $msgid[$qid] = $f[1];
            } elseif (str_starts_with($l['prog'], 'postfix/smtpd') && preg_match('/^client=(\S+)/', $rest, $f)) {
                $client[$qid] = $f[1];
            } elseif (str_starts_with($l['prog'], 'postfix/pickup')) {
                $client[$qid] = 'local';
            }
        }

        return [$from, $msgid, $client];
    }

    /** @return array<string,mixed>|null */
    private function classify(array $l, array $from, array $msgid, array $client): ?array
    {
        $t = $l['text'];
        $prog = $l['prog'];
        $base = ['time' => $l['time'], 'ts' => substr($l['time'], 11, 8), 'qid' => null, 'msgid' => null];

        if (preg_match('/^([A-Za-z0-9]{8,20}): (.*)$/', $t, $m)) {
            [$qid, $rest] = [$m[1], $m[2]];
            $base['qid'] = $qid;
            $base['msgid'] = $msgid[$qid] ?? null;
            $sender = $from[$qid] ?? '?';
            if ((str_starts_with($prog, 'postfix/smtp') || str_starts_with($prog, 'postfix/lmtp') || str_starts_with($prog, 'postfix/error') || str_starts_with($prog, 'postfix/local') || str_starts_with($prog, 'postfix/pipe')) && preg_match('/to=<([^>]*)>.*?relay=([^,]+),.*?status=(\w+) \((.*)\)$/', $rest, $r)) {
                if (str_contains($r[2], '127.0.0.1') && str_contains($r[2], '1002')) {
                    return null; // передача в Amavis и обратно — внутренняя кухня
                }
                $status = $r[3];
                $resp = $r[4];
                if ($status === 'sent') {
                    $isLocal = str_starts_with($prog, 'postfix/lmtp') || str_starts_with($prog, 'postfix/local') || str_starts_with($prog, 'postfix/pipe') || $r[2] === 'dovecot';
                    $folder = 'доставлено в ящик';
                    $what = $isLocal ? $folder : 'доставлено на ' . preg_replace('/\[.*$/', '', $r[2]) . ', ' . (preg_match('/^(\d{3}[ -][\d.]+\s?\w*)/', $resp, $c) ? trim($c[1]) : 'принято');

                    return $base + ['kind' => 'mail', 'who' => $sender . ' → ' . $r[1], 'what' => $what];
                }
                if ($status === 'deferred') {
                    return $base + ['kind' => 'error', 'who' => $sender . ' → ' . $r[1], 'what' => 'отложено: ' . $this->shorten($resp)];
                }
                if ($status === 'bounced') {
                    return $base + ['kind' => 'error', 'who' => $sender . ' → ' . $r[1], 'what' => 'не доставлено: ' . $this->shorten($resp)];
                }

                return $base + ['kind' => 'error', 'who' => $sender . ' → ' . $r[1], 'what' => $status . ': ' . $this->shorten($resp)];
            }

            return null;
        }

        if (str_starts_with($prog, 'postfix/smtpd')) {
            if (preg_match('/^NOQUEUE: reject: RCPT from (\S+): (\d{3}) [\d.]+ (.*?); from=<([^>]*)> to=<([^>]*)>/', $t, $r)) {
                $who = ($r[4] ?: 'MAILER-DAEMON') . ' → ' . $r[5];
                $server = preg_replace('/\[.*$/', '', $r[1]);
                // 4xx — не отказ, а «зайдите позже»: greylisting незнакомого сервера, нормальный сервер повторит через 1–15 минут.
                if ($r[2][0] === '4') {
                    $why = str_contains($r[3], 'Intentional policy rejection') ? 'незнакомый сервер, попросили повторить позже (greylisting)' : $this->shorten($r[3]);
                    return $base + ['kind' => 'grey', 'who' => $who, 'what' => 'отложено: ' . $why . ' (' . $server . ')'];
                }
                $why = str_contains($r[3], 'SMTP AUTH is required') ? 'чужой сервер пишет от имени нашего домена без входа — похоже на подделку адреса (если это сотрудник из mail.ru/Яндекса — Настройки → Антиспам → Отправка с чужих серверов)' : $this->shorten($r[3]);

                return $base + ['kind' => 'spam', 'who' => $who, 'what' => 'отклонено на входе: ' . $why . ' (' . $server . ')'];
            }
            if (preg_match('/^warning: (\S+): SASL \w+ authentication failed/', $t, $r)) {
                return $base + ['kind' => 'auth', 'who' => preg_replace('/^.*\[|\]$/', '', $r[1]) . ' → smtp', 'what' => 'неверный пароль при отправке (SASL)'];
            }

            return null;
        }

        if (str_starts_with($prog, 'amavis')) {
            if (preg_match('/^\((\S+)\) (Passed|Blocked) (\w+)(?: \{(\w+)\})?,.*?<([^>]*)> -> ((?:<[^>]*>,?)+),.*?(?:Queue-ID: (\S+?),)?.*?Message-ID: <([^>]*)>.*?Hits: ([\d.-]+)/', $t, $r)) {
                $to = trim(str_replace(['<', '>'], '', $r[6]), ',');
                $base['qid'] = $r[7] ?: null;
                $base['msgid'] = $r[8];
                $hits = $r[9];
                if ($r[2] === 'Blocked') {
                    return $base + ['kind' => 'spam', 'who' => ($r[5] ?: 'MAILER-DAEMON') . ' → ' . $to, 'what' => ($r[3] === 'SPAM' ? 'спам, оценка ' . $hits . ' — в карантин' : 'заблокировано: ' . strtolower($r[3]))];
                }
                if ($r[3] === 'SPAMMY') {
                    return $base + ['kind' => 'spam', 'who' => ($r[5] ?: 'MAILER-DAEMON') . ' → ' . $to, 'what' => 'похоже на спам, оценка ' . $hits . ' — помечено'];
                }
            }

            return null;
        }

        if ($prog === 'dovecot') {
            if (preg_match('/^(imap|pop3|managesieve)-login: Login: user=<([^>]*)>, method=\w+, rip=([\d.a-f:]+)/', $t, $r)) {
                return $base + ['kind' => 'auth', 'who' => $r[2] . ' · ' . $r[3], 'what' => 'вход ' . strtoupper($r[1]) . ($this->isLan($r[3]) ? ' из сети' : ' из интернета')];
            }
            if (preg_match('/^(imap|pop3|managesieve)-login: .*\(auth failed, (\d+) attempts? in \d+ secs\): user=<([^>]*)>, method=\w+, rip=([\d.a-f:]+)/', $t, $r)) {
                return $base + ['kind' => 'auth', 'who' => $r[4] . ' → ' . strtolower($r[1]), 'what' => 'неверный пароль ' . ($r[3] !== '' ? $r[3] : '(без имени)') . ', попыток: ' . $r[2]];
            }
            if (preg_match('/^lmtp\(([^)]+)\)[^:]*: msgid=<([^>]*)>: saved mail to (.+)$/', $t, $r)) {
                $base['msgid'] = $r[2];

                return $base + ['kind' => 'mail', 'who' => '→ ' . $r[1], 'what' => 'положено в папку ' . $r[3]];
            }
        }

        return null;
    }

    private function shorten(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', $s);

        return mb_strlen($s) > 160 ? mb_substr($s, 0, 157) . '…' : $s;
    }

    private function isLan(string $ip): bool
    {
        return (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|127\.|::1|fe80|fd)/', $ip);
    }

    // ── Путь письма ──────────────────────────────────────────────────────

    /**
     * Все шаги одного письма: по queue-id, message-id или адресу (берётся последнее письмо адреса).
     *
     * @return array{found:bool,subject:?string,from:?string,to:?string,msgid:?string,steps:array<int,array{time:string,title:string,sub:string,kind:string}>,raw:array<int,string>}
     */
    public function path(string $needle): array
    {
        $needle = trim($needle, " <>\t");
        $lines = $this->lines();
        [$from, $msgid, $client] = $this->index($lines);
        $qids = [];

        if (preg_match('/^[A-Za-z0-9]{8,20}$/', $needle) && isset($from[$needle])) {
            $qids[] = $needle;
        } elseif (str_contains($needle, '@') && ! isset($from[$needle])) {
            $byMsgid = array_keys(array_filter($msgid, fn ($m) => strcasecmp($m, $needle) === 0));
            if ($byMsgid) {
                $qids = $byMsgid;
            } else {
                // адрес: последнее письмо, где он отправитель или получатель
                foreach (array_reverse($lines) as $l) {
                    if (preg_match('/^([A-Za-z0-9]{8,20}): /', $l['text'], $m) && stripos($l['text'], '<' . $needle . '>') !== false) {
                        $qids[] = $m[1];
                        break;
                    }
                }
            }
        }
        if (! $qids) {
            return ['found' => false, 'subject' => null, 'from' => null, 'to' => null, 'msgid' => null, 'steps' => [], 'raw' => []];
        }
        // Письмо после Amavis получает новый queue-id — связываем через message-id.
        $mid = $msgid[$qids[0]] ?? null;
        if ($mid) {
            foreach ($msgid as $q => $m) {
                if ($m === $mid && ! in_array($q, $qids, true)) {
                    $qids[] = $q;
                }
            }
        }

        $steps = [];
        $raw = [];
        $to = null;
        $subject = null;
        foreach ($lines as $l) {
            $hit = false;
            foreach ($qids as $q) {
                if (str_contains($l['text'], $q . ': ') || str_contains($l['text'], 'Queue-ID: ' . $q)) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit && $mid && str_contains($l['text'], '<' . $mid . '>') && ($l['prog'] === 'dovecot' || str_starts_with($l['prog'], 'amavis'))) {
                $hit = true;
            }
            if (! $hit) {
                continue;
            }
            $raw[] = $l['raw'];
            $t = $l['text'];
            $ts = substr($l['time'], 11, 8);
            if (str_starts_with($l['prog'], 'postfix/smtpd') && preg_match('/client=(\S+)(.*)$/', $t, $m)) {
                $extra = [];
                if (preg_match('/sasl_username=(\S+)/', $m[2], $s)) {
                    $extra[] = 'отправитель вошёл как ' . $s[1];
                }
                $steps[] = ['time' => $ts, 'title' => 'Принято', 'sub' => 'от ' . $m[1] . ($extra ? ' · ' . implode(', ', $extra) : ''), 'kind' => 'ok'];
            } elseif (str_starts_with($l['prog'], 'postfix/pickup')) {
                $steps[] = ['time' => $ts, 'title' => 'Принято', 'sub' => 'отправлено с самого сервера', 'kind' => 'ok'];
            } elseif (str_starts_with($l['prog'], 'postfix/qmgr') && preg_match('/from=<([^>]*)>, size=(\d+), nrcpt=(\d+)/', $t, $m) && ! isset($seenQmgr)) {
                $seenQmgr = true;
                $steps[] = ['time' => $ts, 'title' => 'В очереди', 'sub' => 'от ' . ($m[1] ?: 'MAILER-DAEMON') . ', ' . $this->size((int) $m[2]) . ', получателей: ' . $m[3], 'kind' => 'ok'];
            } elseif (str_starts_with($l['prog'], 'amavis') && preg_match('/\) (Passed|Blocked) (\w+)(?: \{(\w+)\})?,.*?Hits: ([\d.-]+)/', $t, $m)) {
                $checks = [];
                if (preg_match('/dkim=(\w+)/i', $t, $d)) {
                    $checks[] = 'DKIM ' . $d[1];
                }
                $verdict = $m[1] === 'Passed' ? ($m[2] === 'CLEAN' ? 'чисто' : 'помечено как спам') : 'заблокировано (' . strtolower($m[2]) . ')';
                $steps[] = ['time' => $ts, 'title' => 'Антиспам и антивирус', 'sub' => 'Amavis: оценка ' . $m[4] . ' — ' . $verdict . ($checks ? '; ' . implode(', ', $checks) : ''), 'kind' => $m[1] === 'Passed' && $m[2] === 'CLEAN' ? 'ok' : 'warn'];
            } elseif (preg_match('/^[A-Za-z0-9]+: to=<([^>]*)>.*?relay=([^,]+),.*?status=(\w+) \((.*)\)$/', $t, $m)) {
                if (str_contains($m[2], '127.0.0.1') && str_contains($m[2], '1002')) {
                    $steps[] = ['time' => $ts, 'title' => 'Передано на проверку', 'sub' => 'Postfix → Amavis', 'kind' => 'ok'];
                    continue;
                }
                $to = $to ?: $m[1];
                if ($m[3] === 'sent') {
                    $local = str_starts_with($l['prog'], 'postfix/lmtp') || str_starts_with($l['prog'], 'postfix/local') || str_starts_with($l['prog'], 'postfix/pipe') || $m[2] === 'dovecot';
                    $steps[] = ['time' => $ts, 'title' => $local ? 'Доставлено в ящик' : 'Отправлено', 'sub' => ($local ? 'Dovecot LMTP → ' . $m[1] : preg_replace('/\[.*$/', '', $m[2]) . ' → ' . $m[1] . ': ' . $this->shorten($m[4])), 'kind' => 'ok'];
                } else {
                    $steps[] = ['time' => $ts, 'title' => $m[3] === 'deferred' ? 'Отложено' : 'Не доставлено', 'sub' => $m[1] . ': ' . $this->shorten($m[4]), 'kind' => $m[3] === 'deferred' ? 'warn' : 'no'];
                }
            } elseif ($l['prog'] === 'dovecot' && preg_match('/^lmtp\(([^)]+)\).*?: msgid=<[^>]*>: (.*)$/', $t, $m)) {
                $steps[] = ['time' => $ts, 'title' => 'Разложено по папкам', 'sub' => $m[1] . ': ' . str_replace('saved mail to', 'в папку', $m[2]), 'kind' => 'acc'];
            } elseif (str_starts_with($l['prog'], 'postfix/cleanup') && preg_match('/message-id=<?([^>\s]+)>?/', $t, $m) && ! isset($seenCleanup)) {
                $seenCleanup = true;
            }
        }
        // Тема — из заголовков, если письмо ещё в очереди; иначе из карантина Amavis; для журнала недоступна.
        $qFrom = $from[$qids[0]] ?? null;

        return ['found' => true, 'subject' => $subject, 'from' => $qFrom, 'to' => $to, 'msgid' => $mid, 'qids' => $qids, 'steps' => $steps, 'raw' => $raw];
    }

    // ── Статистика для обзора ────────────────────────────────────────────

    /**
     * @return array{hours:array<int,array{h:string,ok:int,bad:int}>,delivered:int,spam:int,rejected:int,authFail:int,top:array<int,array{addr:string,n:int}>,failedLogins:array}
     */
    public function stats(): array
    {
        return Cache::remember('maillog.stats', 60, function () {
            $since = date('Y-m-d\TH:i:s', time() - 86400);
            $events = $this->events('all', '', 100000, substr($since, 0, 13));
            $hours = [];
            for ($i = 23; $i >= 0; $i--) {
                $h = date('H', time() - $i * 3600);
                $hours[$h] = ['h' => $h . ':00', 'ok' => 0, 'bad' => 0];
            }
            $delivered = $spam = $rejected = $authFail = 0;
            $top = [];
            $failed = [];
            foreach ($events as $e) {
                $h = substr($e['ts'], 0, 2);
                if ($e['kind'] === 'mail' && ! str_starts_with($e['who'], '→')) {
                    $delivered++;
                    if (isset($hours[$h])) {
                        $hours[$h]['ok']++;
                    }
                    $addr = trim(explode('→', $e['who'])[0]);
                    $top[$addr] = ($top[$addr] ?? 0) + 1;
                } elseif ($e['kind'] === 'spam') {
                    $spam++;
                    if (str_starts_with($e['what'], 'отклонено')) {
                        $rejected++;
                    }
                    if (isset($hours[$h])) {
                        $hours[$h]['bad']++;
                    }
                } elseif ($e['kind'] === 'auth' && str_starts_with($e['what'], 'неверный')) {
                    $authFail++;
                    $ip = trim(explode('→', $e['who'])[0]);
                    $failed[$ip] = ($failed[$ip] ?? 0) + 1;
                }
            }
            arsort($top);
            arsort($failed);

            return [
                'hours' => array_values($hours),
                'delivered' => $delivered,
                'spam' => $spam,
                'rejected' => $rejected,
                'authFail' => $authFail,
                'top' => array_map(fn ($a, $n) => ['addr' => $a, 'n' => $n], array_keys(array_slice($top, 0, 6, true)), array_slice($top, 0, 6, true)),
                'failedLogins' => array_map(fn ($a, $n) => ['ip' => $a, 'n' => $n], array_keys(array_slice($failed, 0, 6, true)), array_slice($failed, 0, 6, true)),
            ];
        });
    }

    private function size(int $b): string
    {
        return $b < 1024 ? $b . ' Б' : ($b < 1048576 ? round($b / 1024) . ' КБ' : round($b / 1048576, 1) . ' МБ');
    }

    public function readable(): bool
    {
        return is_readable(self::FILE);
    }
}
