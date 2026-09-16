<?php

namespace App\Services\Mail;

/**
 * Правила пользователя (JSON из интерфейса) → скрипт Sieve.
 *
 * Правило: { id, name, enabled, match: all|any, conditions: [{field, header?, op, value}], actions: [{type, value?}] , stop }
 *   field: from | to | subject | body | header | size | recipient(any of to/cc)
 *   op:    contains | not_contains | is | starts | ends | matches | over | under
 *   action: move(folder) | copy(folder) | label(id) | flag | seen | forward(address) | forward_copy(address) | discard | reply(text) | stop
 *
 * Автоответ: { enabled, from (Y-m-d|null), to (Y-m-d|null), subject, body, days }
 */
class SieveBuilder
{
    public const SCRIPT = 'mailadmin';

    public function build(array $rules, ?array $autoreply, array $labels = []): string
    {
        $need = ['fileinto', 'imap4flags', 'mailbox', 'copy', 'variables'];
        $out = [];

        // Автоответ — первым, чтобы сработал до правил с «остановить».
        if ($autoreply && ! empty($autoreply['enabled'])) {
            $need[] = 'vacation';
            $conds = [];
            if (! empty($autoreply['from'])) {
                $need[] = 'date';
                $need[] = 'relational';
                $conds[] = 'currentdate :value "ge" "date" ' . $this->str($autoreply['from']);
            }
            if (! empty($autoreply['to'])) {
                $need[] = 'date';
                $need[] = 'relational';
                $conds[] = 'currentdate :value "le" "date" ' . $this->str($autoreply['to']);
            }
            $vac = sprintf(
                'vacation :days %d :subject %s %s;',
                max(1, (int) ($autoreply['days'] ?? 1)),
                $this->str($autoreply['subject'] ?? 'Автоответ'),
                $this->str($autoreply['body'] ?? '')
            );
            $out[] = $conds ? "if allof(" . implode(', ', $conds) . ") {\n    {$vac}\n}" : $vac;
        }

        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            $tests = [];
            foreach ($rule['conditions'] ?? [] as $c) {
                if ($t = $this->test($c, $need)) {
                    $tests[] = $t;
                }
            }
            $actions = [];
            foreach ($rule['actions'] ?? [] as $a) {
                if ($s = $this->action($a, $need, $labels)) {
                    $actions[] = $s;
                }
            }
            if (! empty($rule['stop'])) {
                $actions[] = 'stop;';
            }
            if (! $actions) {
                continue;
            }
            $body = "    " . implode("\n    ", $actions);
            $name = '# ' . str_replace(["\r", "\n"], ' ', (string) ($rule['name'] ?? ''));
            if (! $tests) {
                $out[] = "{$name}\n{$body}";
            } else {
                $test = count($tests) === 1 ? $tests[0] : (($rule['match'] ?? 'all') === 'any' ? 'anyof' : 'allof') . '(' . implode(', ', $tests) . ')';
                $out[] = "{$name}\nif {$test} {\n{$body}\n}";
            }
        }

        $need = array_values(array_unique($need));
        $header = 'require [' . implode(', ', array_map(fn ($n) => "\"{$n}\"", $need)) . "];\n";
        $header .= '# Скрипт создан веб-почтой; правила хранятся в приложении. Не редактировать вручную.' . "\n";

        return $header . "\n" . implode("\n\n", $out) . "\n";
    }

    private function test(array $c, array &$need): ?string
    {
        $value = (string) ($c['value'] ?? '');
        $op = $c['op'] ?? 'contains';
        $field = $c['field'] ?? 'subject';

        if ($field === 'size') {
            return 'size :' . ($op === 'under' ? 'under' : 'over') . ' ' . max(1, (int) $value) . 'K';
        }
        if ($value === '') {
            return null;
        }

        $neg = false;
        $match = match ($op) {
            'is' => ':is',
            'starts' => ':matches',
            'ends' => ':matches',
            'matches' => ':matches',
            'not_contains' => ':contains',
            default => ':contains',
        };
        if ($op === 'not_contains') {
            $neg = true;
        }
        $pattern = match ($op) {
            'starts' => $value . '*',
            'ends' => '*' . $value,
            default => $value,
        };

        $t = match ($field) {
            'from' => "address {$match} \"from\" " . $this->str($pattern),
            'to' => "address {$match} \"to\" " . $this->str($pattern),
            'recipient' => "address {$match} [\"to\", \"cc\"] " . $this->str($pattern),
            'body' => $this->body($match, $pattern, $need),
            'header' => "header {$match} " . $this->str((string) ($c['header'] ?? 'Subject')) . ' ' . $this->str($pattern),
            default => "header {$match} \"subject\" " . $this->str($pattern),
        };

        return $neg ? "not {$t}" : $t;
    }

    private function body(string $match, string $pattern, array &$need): string
    {
        $need[] = 'body';

        return "body :text {$match} " . $this->str($pattern);
    }

    private function action(array $a, array &$need, array $labels): ?string
    {
        $v = (string) ($a['value'] ?? '');

        return match ($a['type'] ?? '') {
            'move' => $v !== '' ? 'fileinto :create ' . $this->str($this->folderName($v)) . ';' : null,
            'copy' => $v !== '' ? 'fileinto :copy :create ' . $this->str($this->folderName($v)) . ';' : null,
            'label' => $v !== '' ? 'addflag "Lbl_' . (int) $v . '";' : null,
            'flag' => 'addflag "\\\\Flagged";',
            'seen' => 'addflag "\\\\Seen";',
            'forward' => $v !== '' ? 'redirect ' . $this->str($v) . ';' : null,
            'forward_copy' => $v !== '' ? 'redirect :copy ' . $this->str($v) . ';' : null,
            'discard' => 'discard;',
            'reply' => $this->reply($v, $need),
            'stop' => 'stop;',
            default => null,
        };
    }

    private function reply(string $text, array &$need): ?string
    {
        if ($text === '') {
            return null;
        }
        $need[] = 'vacation';

        return 'vacation :days 1 ' . $this->str($text) . ';';
    }

    /** Путь папки из IMAP (UTF7) → имя для Sieve (UTF-8, разделитель как у сервера). */
    private function folderName(string $path): string
    {
        return mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP') ?: $path;
    }

    private function str(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }
}
