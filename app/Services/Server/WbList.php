<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\DB;

/**
 * Белый и чёрный списки отправителей — таблицы wblist/mailaddr/users в базе amavisd
 * (их читает плагин amavisd_wblist в iRedAPD и сам Amavis). Работаем с общесерверным
 * уровнем: получатель «@.» (все).
 */
class WbList
{
    private const GLOBAL = '@.';

    /** @return array<int,array{id:int,email:string,wb:string,note:string}> */
    public function all(): array
    {
        $rid = $this->globalRid();
        if (! $rid) {
            return [];
        }
        $rows = DB::connection('amavisd')->table('wblist')->join('mailaddr', 'mailaddr.id', '=', 'wblist.sid')
            ->where('wblist.rid', $rid)->orderBy('mailaddr.email')->get(['mailaddr.id', 'mailaddr.email', 'wblist.wb']);
        $notes = DB::table('app_settings')->where('key', 'wblist_notes')->value('value');
        $notes = $notes ? (json_decode($notes, true) ?: []) : [];

        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'email' => $r->email, 'wb' => strtoupper($r->wb) === 'W' ? 'W' : 'B', 'note' => $notes[$r->email] ?? ''])->all();
    }

    public function add(string $pattern, string $wb, string $note = ''): void
    {
        $pattern = strtolower(trim($pattern));
        if (! preg_match('/^(\*@)?[a-z0-9.@_+-]+$/i', $pattern) && ! preg_match('/^@[a-z0-9.-]+$/', $pattern)) {
            throw new \InvalidArgumentException('Укажите адрес, *@домен или @домен');
        }
        // В amavisd домен целиком записывается как «@domain».
        $pattern = str_starts_with($pattern, '*@') ? substr($pattern, 1) : $pattern;
        $db = DB::connection('amavisd');
        $rid = $this->globalRid(true);
        $sid = $db->table('mailaddr')->where('email', $pattern)->value('id');
        if (! $sid) {
            $priority = str_starts_with($pattern, '@') ? 20 : 30;
            $sid = $db->table('mailaddr')->insertGetId(['priority' => $priority, 'email' => $pattern]);
        }
        $db->table('wblist')->where('rid', $rid)->where('sid', $sid)->delete();
        $db->table('wblist')->insert(['rid' => $rid, 'sid' => $sid, 'wb' => $wb === 'W' ? 'W' : 'B']);
        $this->note($pattern, $note);
    }

    public function remove(int $sid): void
    {
        $db = DB::connection('amavisd');
        $rid = $this->globalRid();
        $email = $db->table('mailaddr')->where('id', $sid)->value('email');
        $db->table('wblist')->where('rid', $rid)->where('sid', $sid)->delete();
        if (! $db->table('wblist')->where('sid', $sid)->exists()) {
            $db->table('mailaddr')->where('id', $sid)->delete();
        }
        if ($email) {
            $this->note($email, null);
        }
    }

    private function globalRid(bool $create = false): ?int
    {
        $db = DB::connection('amavisd');
        $id = $db->table('users')->where('email', self::GLOBAL)->value('id');
        if (! $id && $create) {
            $id = $db->table('users')->insertGetId(['priority' => 0, 'policy_id' => 0, 'email' => self::GLOBAL, 'fullname' => 'Все получатели']);
        }

        return $id ? (int) $id : null;
    }

    private function note(string $email, ?string $note): void
    {
        $row = DB::table('app_settings')->where('key', 'wblist_notes')->value('value');
        $notes = $row ? (json_decode($row, true) ?: []) : [];
        if ($note === null) {
            unset($notes[$email]);
        } else {
            $notes[$email] = mb_substr($note, 0, 120);
        }
        DB::table('app_settings')->updateOrInsert(['key' => 'wblist_notes'], ['value' => json_encode($notes, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()]);
    }
}
