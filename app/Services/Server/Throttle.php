<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\DB;

/**
 * Лимит исходящих на один ящик — плагин throttle iRedAPD (таблица iredapd.throttle).
 * Одно общее правило для всех отправителей: account = '@.' (все), kind = outbound.
 */
class Throttle
{
    private const ACCOUNT = '@.';

    /** @return array{max_msgs:int,period_min:int,enabled:bool} */
    public static function get(): array
    {
        $row = DB::connection('iredapd')->table('throttle')->where('account', self::ACCOUNT)->where('kind', 'outbound')->first();
        if (! $row || (int) $row->max_msgs <= 0) {
            return ['max_msgs' => 0, 'period_min' => 60, 'enabled' => false];
        }

        return ['max_msgs' => (int) $row->max_msgs, 'period_min' => max(1, (int) round($row->period / 60)), 'enabled' => true];
    }

    /** 0 писем — правило снимается. */
    public static function set(int $maxMsgs, int $periodMin): void
    {
        $db = DB::connection('iredapd');
        if ($maxMsgs <= 0) {
            $db->table('throttle')->where('account', self::ACCOUNT)->where('kind', 'outbound')->delete();

            return;
        }
        $db->table('throttle')->updateOrInsert(
            ['account' => self::ACCOUNT, 'kind' => 'outbound'],
            ['priority' => 0, 'period' => $periodMin * 60, 'msg_size' => -1, 'max_msgs' => $maxMsgs, 'max_quota' => -1, 'max_rcpts' => -1],
        );
    }

    /** Кто сейчас упёрся в лимит (для страницы безопасности). @return array<int,array{user:string,msgs:int}> */
    public static function hot(): array
    {
        try {
            $rows = DB::connection('iredapd')->table('throttle_tracking')->where('kind', 'outbound')->orderByDesc('cur_msgs')->limit(10)->get(['account', 'cur_msgs']);

            return $rows->map(fn ($r) => ['user' => $r->account, 'msgs' => (int) $r->cur_msgs])->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
