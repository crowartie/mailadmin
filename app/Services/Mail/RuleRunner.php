<?php

namespace App\Services\Mail;

use App\Models\Webmail\Label;

/**
 * Прогнать правила сотрудника по уже полученной почте («Входящие»).
 * Sieve работает только для новых писем; здесь те же условия переводятся в IMAP SEARCH.
 * Поддерживаются условия по отправителю, получателю, теме и заголовку; действия — папка, копия, метка, флажок,
 * прочитано, удалить. Остальное (пересылка, автоответ, размер, тело) для старой почты не применяется.
 */
class RuleRunner
{
    public function __construct(private readonly MailStore $store, private readonly string $user)
    {
    }

    /** @return array<int,array{id:mixed,name:string,count:int,skipped:?string}> */
    public function run(array $rules, ?string $folder = null): array
    {
        $folder ??= $this->store->rolePath('inbox');
        $labels = Label::where('user', $this->user)->pluck('name', 'id')->all();
        $out = [];
        $stopped = [];
        foreach ($rules as $rule) {
            $row = ['id' => $rule['id'] ?? null, 'name' => (string) ($rule['name'] ?? ''), 'count' => 0, 'skipped' => null];
            if (empty($rule['enabled'])) {
                $row['skipped'] = 'выключено';
                $out[] = $row;
                continue;
            }
            $unsupported = collect($rule['conditions'] ?? [])->first(fn ($c) => ! $this->supported($c));
            if ($unsupported) {
                $row['skipped'] = 'условие по ' . ($unsupported['field'] === 'size' ? 'размеру' : ($unsupported['field'] === 'body' ? 'тексту письма' : 'шаблону')) . ' для старой почты не применяется';
                $out[] = $row;
                continue;
            }
            $applicable = fn (array $list) => collect($list)->filter(fn ($a) => in_array($a['type'] ?? '', self::APPLICABLE, true));
            $actions = $applicable($rule['actions'] ?? []);
            $refines = collect($rule['refine'] ?? [])->filter(fn ($r) => $applicable($r['actions'] ?? [])->isNotEmpty() && ! collect($r['conditions'] ?? [])->first(fn ($c) => ! $this->supported($c)));
            if ($actions->isEmpty() && $refines->isEmpty()) {
                $row['skipped'] = 'нет действий, применимых к старой почте';
                $out[] = $row;
                continue;
            }
            $uids = $this->match($folder, $rule);
            $uids = array_values(array_diff($uids, $stopped));
            $row['count'] = count($uids);
            // Уточнения — по порядку, каждое забирает свои письма; остаток — основным действиям.
            $rest = $uids;
            foreach ($refines as $ref) {
                $mine = $ref['conditions'] ? array_values(array_intersect($rest, $this->match($folder, $ref))) : $rest;
                if ($mine) {
                    $this->applyAll($folder, $mine, $applicable($ref['actions'] ?? []), $labels);
                    $rest = array_values(array_diff($rest, $mine));
                }
            }
            if ($rest && $actions->isNotEmpty()) {
                $this->applyAll($folder, $rest, $actions, $labels);
            }
            // «Остановить» — или письмо уже перенесено/удалено: следующим правилам оно не достанется.
            $moves = fn ($list) => collect($list)->contains(fn ($a) => in_array($a['type'] ?? '', ['move', 'discard'], true));
            if ($uids && (! empty($rule['stop']) || $moves($rule['actions'] ?? []) || $refines->contains(fn ($r) => $moves($r['actions'] ?? [])))) {
                $stopped = array_merge($stopped, $uids);
            }
            $out[] = $row;
        }

        return $out;
    }

    private const APPLICABLE = ['move', 'copy', 'label', 'flag', 'seen', 'discard'];

    /** Действия по порядку; после переноса или удаления письма во «Входящих» уже нет — дальше не идём. */
    private function applyAll(string $folder, array $uids, \Illuminate\Support\Collection $actions, array $labels): void
    {
        foreach ($actions as $a) {
            $this->apply($folder, $uids, $a, $labels);
            if (in_array($a['type'], ['move', 'discard'], true)) {
                break;
            }
        }
    }

    private function supported(array $c): bool
    {
        return in_array($c['field'] ?? '', ['from', 'to', 'recipient', 'subject', 'header'], true)
            && in_array($c['op'] ?? '', ['contains', 'not_contains', 'is', 'starts', 'ends'], true);
    }

    /** @return int[] */
    private function match(string $folder, array $rule): array
    {
        $conds = $rule['conditions'] ?? [];
        if (! $conds) {
            return $this->store->searchAll($folder);
        }
        $sets = [];
        foreach ($conds as $c) {
            $sets[] = $this->store->searchCondition($folder, $c);
        }
        if (($rule['match'] ?? 'all') === 'any') {
            return array_values(array_unique(array_merge(...$sets)));
        }
        $r = array_shift($sets);
        foreach ($sets as $s) {
            $r = array_intersect($r, $s);
        }

        return array_values($r);
    }

    private function apply(string $folder, array $uids, array $a, array $labels): void
    {
        $v = (string) ($a['value'] ?? '');
        switch ($a['type']) {
            case 'move':
                if ($v !== '') {
                    $this->store->move($folder, $uids, $this->store->ensureFolder($v));
                }
                break;
            case 'copy':
                if ($v !== '') {
                    $this->store->copy($folder, $uids, $this->store->ensureFolder($v));
                }
                break;
            case 'label':
                if (isset($labels[(int) $v])) {
                    $this->store->flag($folder, $uids, 'Lbl_' . (int) $v, true);
                }
                break;
            case 'flag':
                $this->store->flag($folder, $uids, '\\Flagged', true);
                break;
            case 'seen':
                $this->store->flag($folder, $uids, '\\Seen', true);
                break;
            case 'discard':
                $this->store->delete($folder, $uids);
                break;
        }
    }
}
