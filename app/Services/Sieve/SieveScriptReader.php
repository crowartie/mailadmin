<?php

namespace App\Services\Sieve;

use App\Models\Vmail\Mailbox;

/**
 * Чтение и упрощённый разбор Sieve-скриптов Dovecot.
 *
 * Разбираем только то, что генерируют SOGo и наш будущий редактор правил:
 * `if <test> { <actions> }` с тестами header/address/size/currentdate/allof/anyof
 * и действиями fileinto/redirect/discard/reject/vacation/addflag/stop. Всё, что не
 * поняли, попадает в правило вида «Правило Sieve» с исходным текстом — ничего не теряется.
 */
class SieveScriptReader
{
    /**
     * Активный скрипт ящика: Dovecot держит его в Maildir как `sieve/active` (симлинк)
     * либо `dovecot.sieve`/`.dovecot.sieve` в корне домашнего каталога.
     */
    public function activeScript(Mailbox $mailbox): ?string
    {
        $home = rtrim($mailbox->maildir_path, '/');

        foreach (['/sieve/active', '/dovecot.sieve', '/.dovecot.sieve', '/sieve/managesieve.sieve'] as $candidate) {
            $path = $home . $candidate;
            if (is_readable($path)) {
                return (string) file_get_contents($path);
            }
        }

        return null;
    }

    /**
     * @return array<int,array{kind:string,condition:string,action:string,active:bool,until:?string,raw:string}>
     */
    public function parse(string $script): array
    {
        $rules = [];

        // Отключённые правила SOGo хранит закомментированными — сохраняем как выключенные.
        preg_match_all('/^\s*#\s*rule:\[(.+?)\]\s*$/m', $script, $disabled);
        foreach ($disabled[1] as $name) {
            $rules[] = [
                'kind' => 'other',
                'condition' => $name,
                'action' => 'правило выключено',
                'active' => false,
                'until' => null,
                'raw' => "# rule:[{$name}]",
            ];
        }

        // Блоки if ... { ... }. Каждый найденный вырезаем из остатка,
        // чтобы поиск автоответа верхнего уровня не залез внутрь блока.
        $rest = $script;
        if (preg_match_all('/(?:^|\n)\s*(?:els)?if\s+(.+?)\s*\{(.*?)\n\s*\}/s', $script, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $block) {
                $rest = str_replace($block[0], '', $rest);
                $condition = $this->describeTest(trim($block[1]));
                $body = trim($block[2]);

                if (str_contains($body, 'vacation')) {
                    // Срок автоответа SOGo кладёт в условие (currentdate), поэтому отдаём весь блок.
                    $rules[] = $this->vacationRule($block[0], $condition);
                    continue;
                }

                [$kind, $action] = $this->describeActions($body);
                $rules[] = [
                    'kind' => $kind,
                    'condition' => $condition,
                    'action' => $action,
                    'active' => true,
                    'until' => null,
                    'raw' => trim($block[0]),
                ];
            }
        }

        // Автоответ верхнего уровня (SOGo пишет его без if).
        if (preg_match('/^\s*vacation\b(.*?);/ms', $rest, $m)) {
            $rules[] = $this->vacationRule($m[0], 'Любое входящее');
        }

        return $rules;
    }

    private function describeTest(string $test): string
    {
        $test = preg_replace('/\s+/', ' ', $test);

        $parts = [];
        $joiner = ' и ';
        if (preg_match('/^anyof\s*\((.*)\)$/s', $test, $m)) {
            $joiner = ' или ';
            $test = $m[1];
        } elseif (preg_match('/^allof\s*\((.*)\)$/s', $test, $m)) {
            $test = $m[1];
        }

        preg_match_all('/(not\s+)?(header|address|envelope)\s+:(contains|is|matches|regex)\s+"([^"]+)"\s+"([^"]+)"/i', $test, $hs, PREG_SET_ORDER);
        foreach ($hs as $h) {
            $field = match (strtolower($h[4])) {
                'subject' => 'Тема',
                'from' => 'Отправитель',
                'to' => 'Получатель',
                'cc' => 'Копия',
                default => $h[4],
            };
            $op = match (strtolower($h[3])) {
                'contains' => 'содержит',
                'is' => '—',
                'matches' => 'похоже на',
                'regex' => 'подходит под',
            };
            $parts[] = trim(($h[1] ? 'не ' : '') . "{$field} {$op} «{$h[5]}»");
        }

        if (preg_match('/size\s+:(over|under)\s+(\d+)([KMG]?)/i', $test, $s)) {
            $size = (int) $s[2] . ['' => ' Б', 'K' => ' КБ', 'M' => ' МБ', 'G' => ' ГБ'][strtoupper($s[3])];
            $parts[] = (strtolower($s[1]) === 'over' ? 'Письмо больше ' : 'Письмо меньше ') . $size;
        }

        // Срок действия правила: currentdate :value "ge"/"le" "date" "ГГГГ-ММ-ДД".
        $dates = [];
        if (preg_match_all('/currentdate\s+:value\s+"(ge|le)"\s+"date"\s+"(\d{4})-(\d{2})-(\d{2})"/i', $test, $ds, PREG_SET_ORDER)) {
            foreach ($ds as $d) {
                $dates[] = (strtolower($d[1]) === 'ge' ? 'с ' : 'по ') . "{$d[4]}.{$d[3]}.{$d[2]}";
            }
        }

        if (! $parts && $dates) {
            return 'Любое входящее ' . implode(' ', $dates);
        }

        if ($parts && $dates) {
            $parts[] = implode(' ', $dates);
        }

        if (preg_match('/\btrue\b/', $test) && ! $parts) {
            return 'Любое входящее';
        }

        return $parts ? implode($joiner, $parts) : 'Правило Sieve: ' . mb_strimwidth($test, 0, 120, '…');
    }

    /**
     * @return array{0:string,1:string}
     */
    private function describeActions(string $body): array
    {
        $actions = [];
        $kind = 'other';

        if (preg_match_all('/fileinto\s+(?::copy\s+)?"([^"]+)"/', $body, $m)) {
            $kind = 'fileinto';
            foreach ($m[1] as $folder) {
                $actions[] = 'Переместить в «' . str_replace(['INBOX/', 'INBOX.'], '', $folder) . '»';
            }
        }
        if (preg_match_all('/redirect\s+(:copy\s+)?"([^"]+)"/', $body, $m, PREG_SET_ORDER)) {
            $kind = 'redirect';
            foreach ($m as $r) {
                $actions[] = ($r[1] ? 'Копия на ' : 'Переслать на ') . $r[2];
            }
        }
        if (preg_match_all('/addflag\s+"([^"]+)"/', $body, $m)) {
            foreach ($m[1] as $flag) {
                $actions[] = $flag === '\\Flagged' ? 'Пометить важным' : ($flag === '\\Seen' ? 'Пометить прочитанным' : "Флаг {$flag}");
            }
        }
        if (str_contains($body, 'discard')) {
            $kind = 'discard';
            $actions[] = 'Удалить';
        }
        if (str_contains($body, 'reject')) {
            $kind = 'discard';
            $actions[] = 'Отклонить с уведомлением';
        }
        if (preg_match('/\bstop\s*;/', $body)) {
            $actions[] = 'остановить обработку';
        }

        return [$kind, $actions ? implode(', ', $actions) : 'Правило Sieve: ' . mb_strimwidth(preg_replace('/\s+/', ' ', $body), 0, 120, '…')];
    }

    /**
     * @return array{kind:string,condition:string,action:string,active:bool,until:?string,raw:string}
     */
    private function vacationRule(string $block, string $condition): array
    {
        $subject = preg_match('/:subject\s+"([^"]*)"/', $block, $m) ? $m[1] : 'Автоответ';
        $until = null;

        if (preg_match('/currentdate\s+:value\s+"le"\s+"date"\s+"(\d{4}-\d{2}-\d{2})"/', $block, $d)) {
            $until = $d[1];
        }

        return [
            'kind' => 'vacation',
            'condition' => $condition,
            'action' => "Автоответ «{$subject}»",
            'active' => true,
            'until' => $until,
            'raw' => trim($block),
        ];
    }
}
