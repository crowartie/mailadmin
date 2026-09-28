<?php

namespace App\Services\Mail;

/**
 * Правила пользователя (JSON из интерфейса) → скрипт Sieve.
 *
 * Правило: { id, name, enabled, match: all|any, conditions: [{field, header?, op, value}], actions: [{type, value?}] , stop,
 *            refine: [{match, conditions, actions}] }
 *   field: from | to | subject | body | header | size | recipient(any of to/cc)
 *   op:    contains | not_contains | is | starts | ends | matches | over | under
 *   action: move(folder) | copy(folder) | move_by_sender(parent) | move_by_domain(parent) | move_by_name(parent)
 *           | label(id) | flag | seen | forward(address) | forward_copy(address) | discard | reply(text) | stop
 *   refine: уточнения внутри правила — проверяются по порядку раньше основных действий; подошло — выполняется
 *           только оно, основные действия — для писем, не подошедших ни под одно уточнение (вложенный if/elsif/else).
 *
 * Автоответ: { enabled, from (Y-m-d|null), to (Y-m-d|null), subject, body, days }
 * Свой скрипт (custom): текст Sieve, который человек написал сам; его require сливаются с нашими,
 * а тело идёт после правил конструктора.
 */
class SieveBuilder
{
    public const SCRIPT = 'mailadmin';

    public function build(array $rules, ?array $autoreply, array $labels = [], ?string $custom = null): string
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
            // Уточнения: if/elsif по порядку, основные действия — в else.
            $branches = [];
            foreach ($rule['refine'] ?? [] as $ref) {
                $rt = [];
                foreach ($ref['conditions'] ?? [] as $c) {
                    if ($t = $this->test($c, $need)) {
                        $rt[] = $t;
                    }
                }
                $ra = [];
                foreach ($ref['actions'] ?? [] as $a) {
                    if ($s = $this->action($a, $need, $labels)) {
                        $ra[] = $s;
                    }
                }
                if (! $ra) {
                    continue;
                }
                $test = ! $rt ? 'true' : (count($rt) === 1 ? $rt[0] : (($ref['match'] ?? 'all') === 'any' ? 'anyof' : 'allof') . '(' . implode(', ', $rt) . ')');
                $branches[] = [$test, $ra];
            }
            if (! $actions && ! $branches) {
                continue;
            }
            if ($branches) {
                $lines = [];
                foreach ($branches as $i => [$test, $ra]) {
                    $lines[] = ($i === 0 ? 'if ' : '} elsif ') . $test . ' {';
                    foreach ($ra as $s) {
                        $lines[] = '    ' . $s;
                    }
                }
                if ($actions) {
                    $lines[] = '} else {';
                    foreach ($actions as $s) {
                        $lines[] = '    ' . $s;
                    }
                }
                $lines[] = '}';
                $actions = $lines;
            }
            if (! empty($rule['stop'])) {
                $actions[] = 'stop;';
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

        // Свой скрипт: его require — в общую шапку (в Sieve require допустим только в начале), тело — в конец.
        $custom = trim((string) $custom);
        if ($custom !== '') {
            [$extra, $customBody] = self::splitRequire($custom);
            $need = array_merge($need, $extra);
            if ($customBody !== '') {
                $out[] = "# ── Свой скрипт ──\n" . $customBody;
            }
        }

        $need = array_values(array_unique($need));
        $header = 'require [' . implode(', ', array_map(fn ($n) => "\"{$n}\"", $need)) . "];\n";
        $header .= '# Скрипт создан веб-почтой; правила хранятся в приложении. Не редактировать вручную.' . "\n";

        return $header . "\n" . implode("\n\n", $out) . "\n";
    }

    /**
     * Вынуть из своего скрипта строки require: имена расширений — в общую шапку, остальное — как есть.
     * @return array{0:array<int,string>,1:string}
     */
    public static function splitRequire(string $script): array
    {
        $names = [];
        $body = preg_replace_callback('/^[ \t]*require[ \t]+(\[[^\]]*\]|"[^"]*")[ \t]*;[ \t]*\r?\n?/m', function ($m) use (&$names) {
            preg_match_all('/"([^"]+)"/', $m[1], $q);
            foreach ($q[1] as $n) {
                $names[] = $n;
            }

            return '';
        }, $script) ?? $script;

        return [array_values(array_unique($names)), trim($body)];
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
            'move_by_sender' => $this->bySender('localpart', $v),
            'move_by_domain' => $this->bySender('domain', $v),
            'move_by_name' => $this->byName($v),
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
    /**
     * Папка по отправителю: «Родитель/<часть адреса до @>» (или по домену — «polyus» из polyus.com).
     *
     * Точка в имени папки — служебный разделитель Dovecot: «ivan.ivanov» дал бы папку «ivan»
     * с вложенной «ivanov». Sieve не умеет заменять символы, поэтому части адреса собираются
     * заново через «-»: до трёх частей, что покрывает почти все адреса. Для домена последняя
     * часть (зона .com/.ru) отбрасывается — «polyus.com» → «polyus», «mail.polyus.com» → «mail-polyus».
     * Переменная ${n} задаётся внутри блока правила, поэтому на другие правила не влияет.
     */
    private function bySender(string $part, string $parent): string
    {
        return $this->senderChain($part) . ' fileinto :create ' . $this->varTarget($parent) . ';';
    }

    /** Цепочка if/elsif, кладущая в ${n} часть адреса (localpart или domain) с точками, заменёнными на «-». */
    private function senderChain(string $part): string
    {
        $joined = $part === 'domain'
            ? ['*.*.*' => '${1}-${2}', '*.*' => '${1}', '*' => '${1}']
            : ['*.*.*' => '${1}-${2}-${3}', '*.*' => '${1}-${2}', '*' => '${1}'];
        $script = '';
        foreach ($joined as $pattern => $value) {
            $script .= ($script === '' ? 'if ' : ' elsif ') . "address :matches :{$part} \"from\" \"{$pattern}\" { set \"n\" \"{$value}\"; }";
        }

        return $script;
    }

    /** «Родитель/${n}»: ${n} подставляется в момент выполнения; кавычки родителя экранирует str(). */
    private function varTarget(string $parent): string
    {
        $prefix = $parent !== '' ? $this->folderName($parent) . '/' : '';

        return substr($this->str($prefix), 0, -1) . '${n}"';
    }

    /**
     * Папка по имени отправителя из поля «От»: «"Иванов Иван" <ivanov@x>» или «Иванов Иван <ivanov@x>» → «Иванов Иван».
     * Sieve сравнивает заголовки уже раскодированными, так что кириллица приходит как есть. Имени нет, либо в нём
     * точка или косая черта (разделители папок: «Иванов И.И.» дал бы вложенные папки) — тогда по адресу, как move_by_sender.
     */
    private function byName(string $parent): string
    {
        $s = 'if header :matches "from" "\\"*\\" <*>" { set "n" "${1}"; } elsif header :matches "from" "* <*>" { set "n" "${1}"; } else { set "n" ""; }';
        $s .= ' if string :matches "${n}" ["", "*.*", "*/*", " *", "* "] { ' . $this->senderChain('localpart') . ' }';

        return $s . ' fileinto :create ' . $this->varTarget($parent) . ';';
    }

    private function folderName(string $path): string
    {
        return mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP') ?: $path;
    }

    private function str(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }
}
