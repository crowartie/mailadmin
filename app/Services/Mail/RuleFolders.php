<?php

namespace App\Services\Mail;

use App\Models\Webmail\Label;
use App\Models\Webmail\RuleSet;
use Illuminate\Support\Facades\Log;

/**
 * Правила и папки живут порознь: правило хранит путь папки, а папку человек может переименовать
 * или удалить. Раньше правило продолжало ссылаться на старый путь, и sieve при первом же письме
 * заново создавал папку с прежним именем — «удалённая» или «переименованная» папка воскресала.
 * Здесь правила подтягиваются вслед за папкой, и sieve-скрипт перевыпускается.
 */
final class RuleFolders
{
    public function __construct(private readonly SieveBuilder $builder)
    {
    }

    /**
     * Папка переименована: пути в действиях правил меняются вместе с ней (и у вложенных папок тоже).
     *
     * @return int сколько правил поправлено
     */
    public function renamed(ImapSession $session, string $old, string $new): int
    {
        return $this->rewrite($session, function (string $path) use ($old, $new): ?string {
            if ($path === $old) {
                return $new;
            }
            foreach (['/', '.'] as $sep) {
                if (str_starts_with($path, $old . $sep)) {
                    return $new . substr($path, strlen($old));
                }
            }

            return null;
        }, false);
    }

    /**
     * Папка удалена: правила, которые клали в неё письма, выключаются — иначе sieve создал бы её
     * заново при первом подходящем письме. Само правило остаётся, человек увидит его выключенным.
     *
     * @return int сколько правил выключено
     */
    public function deleted(ImapSession $session, string $path): int
    {
        return $this->rewrite($session, function (string $target) use ($path): ?string {
            if ($target === $path || str_starts_with($target, $path . '/') || str_starts_with($target, $path . '.')) {
                return '';   // пустая цель = правило выключить
            }

            return null;
        }, true);
    }

    /**
     * @param  callable(string):?string  $map  новый путь для цели правила; null — не трогать; '' — выключить правило
     */
    private function rewrite(ImapSession $session, callable $map, bool $disable): int
    {
        $user = $session->user();
        $set = RuleSet::find($user);
        if (! $set || ! is_array($set->rules) || $set->rules === []) {
            return 0;
        }
        $rules = $set->rules;
        $touched = 0;
        foreach ($rules as &$rule) {
            $hit = false;
            foreach ((array) ($rule['actions'] ?? []) as $i => $a) {
                if (! in_array($a['type'] ?? '', ['move', 'copy', 'move_by_sender', 'move_by_domain'], true) || ! is_string($a['value'] ?? null) || $a['value'] === '') {
                    continue;
                }
                $to = $map($a['value']);
                if ($to === null) {
                    continue;
                }
                $hit = true;
                if ($to !== '') {
                    $rule['actions'][$i]['value'] = $to;
                }
            }
            if ($hit) {
                $touched++;
                if ($disable) {
                    $rule['enabled'] = false;
                }
            }
        }
        unset($rule);
        if ($touched === 0) {
            return 0;
        }

        $labels = Label::where('user', $user)->pluck('name', 'id')->all();
        $script = $this->builder->build($rules, $set->autoreply, $labels);
        try {
            $sieve = ManageSieveClient::forUser($session->loginName(), $session->password());
            $sieve->putScript(SieveBuilder::SCRIPT, $script);
            $sieve->setActive(SieveBuilder::SCRIPT);
            $sieve->logout();
        } catch (\Throwable $e) {
            // Правила в базе всё равно поправим: следующее сохранение правил перевыпустит скрипт.
            Log::warning('правила после переименования папки: sieve не обновлён у ' . $user . ': ' . $e->getMessage());
        }
        $set->rules = $rules;
        $set->script = $script;
        $set->save();

        return $touched;
    }
}
