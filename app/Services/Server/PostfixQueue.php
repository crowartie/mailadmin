<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\Cache;

/**
 * Очередь Postfix: чтение через `postqueue -j` (по объекту JSON на письмо), действия через postsuper/postqueue.
 */
class PostfixQueue
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        [$code, $out] = Ctl::run('queue-json', [], 20);
        if ($code !== 0) {
            return [];
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $j = json_decode($line, true);
            if (! is_array($j)) {
                continue;
            }
            $recipients = array_map(fn ($r) => ['address' => $r['address'] ?? '', 'reason' => trim((string) ($r['delay_reason'] ?? ''))], $j['recipients'] ?? []);
            $reasons = array_values(array_unique(array_filter(array_column($recipients, 'reason'))));
            $rows[] = [
                'id' => $j['queue_id'] ?? '',
                'queue' => $j['queue_name'] ?? '',
                'state' => match ($j['queue_name'] ?? '') {
                    'active' => 'active', 'hold' => 'hold', 'deferred' => 'deferred', 'incoming', 'maildrop' => 'incoming', default => 'deferred',
                },
                'arrival' => isset($j['arrival_time']) ? date(DATE_ATOM, (int) $j['arrival_time']) : null,
                'age' => isset($j['arrival_time']) ? time() - (int) $j['arrival_time'] : null,
                'size' => (int) ($j['message_size'] ?? 0),
                'sender' => (string) ($j['sender'] ?? '') ?: 'MAILER-DAEMON',
                'recipients' => $recipients,
                'reason' => $reasons[0] ?? '',
                'forcedExpire' => (bool) ($j['forced_expire'] ?? false),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($b['arrival'] ?? '', $a['arrival'] ?? ''));

        return $rows;
    }

    /** Размер очереди для счётчика в меню (кэш 30 с). */
    public function count(): int
    {
        return Cache::remember('queue.count', 30, fn () => count($this->all()));
    }

    /** @return array{headers:string,tries:array<int,string>} */
    public function details(string $id): array
    {
        [$code, $out] = Ctl::run('queue-headers', [$id], 15);
        $headers = $code === 0 ? $this->cleanHeaders($out) : '';
        $tries = [];
        foreach ((new MailLog())->linesFor($id) as $line) {
            if (preg_match('/postfix\/(?:smtp|lmtp|error)\[\d+\]: [A-Z0-9]+: to=<[^>]*>,.*?status=(\w+) \((.*)\)$/', $line['raw'], $m)) {
                $tries[] = $line['time'] . ' ' . $m[1] . ': ' . $m[2];
            }
        }

        return ['headers' => $headers, 'tries' => array_slice($tries, -10)];
    }

    public function raw(string $id): string
    {
        return Ctl::out('queue-cat', [$id], 30);
    }

    /** @param string[] $ids */
    public function action(string $op, array $ids): void
    {
        $ids = array_values(array_filter($ids, fn ($i) => preg_match('/^[A-Za-z0-9]{6,20}$/', $i)));
        if ($op === 'flush') {
            Ctl::out('queue-flush');
        } elseif ($ids) {
            Ctl::out(match ($op) {
                'retry' => 'queue-retry', 'hold' => 'queue-hold', 'release' => 'queue-release', 'delete' => 'queue-delete',
                default => throw new \InvalidArgumentException('Неизвестное действие'),
            }, $ids, 60);
        }
        Cache::forget('queue.count');
    }

    private function cleanHeaders(string $out): string
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $out) as $l) {
            if (preg_match('/^(Subject|From|To|Cc|Date|Message-ID|Content-Type|X-Mailer|X-Spam-Status|Received):/i', $l) || preg_match('/^\s+\S/', $l)) {
                $lines[] = $l;
            }
        }
        $text = implode("\n", $lines);
        if (preg_match_all('/^Subject:\s*(.*)$/mi', $text, $m)) {
            $text = str_replace($m[0][0], 'Subject: ' . iconv_mime_decode($m[1][0], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'), $text);
        }

        return $text;
    }
}
