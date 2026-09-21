<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

class RuleSet extends Model
{
    /**
     * Куда у человека уже раскладываются письма по отправителю: «адрес или домен» → путь папки.
     * Нужно окну «класть сюда все письма от этого отправителя?»: если правило есть, спрашивать не о чем.
     *
     * @return array<string,string>
     */
    public static function senderFolders(string $user): array
    {
        $set = self::find($user);
        $out = [];
        foreach ((array) ($set->rules ?? []) as $rule) {
            if (! is_array($rule) || ($rule['enabled'] ?? true) === false) {
                continue;
            }
            $to = null;
            foreach ((array) ($rule['actions'] ?? []) as $a) {
                if (($a['type'] ?? '') === 'move' && filled($a['value'] ?? null)) {
                    $to = (string) $a['value'];
                    break;
                }
            }
            if ($to === null) {
                continue;
            }
            foreach ((array) ($rule['conditions'] ?? []) as $c) {
                if (($c['field'] ?? '') !== 'from' || ! in_array($c['op'] ?? '', ['is', 'contains', 'ends'], true)) {
                    continue;
                }
                $v = mb_strtolower(trim((string) ($c['value'] ?? ''), " @"));
                if ($v !== '') {
                    $out[$v] = $to;
                }
            }
        }

        return $out;
    }

    protected $table = 'webmail_rules';

    protected $primaryKey = 'user';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['rules' => 'array', 'autoreply' => 'array'];
}
