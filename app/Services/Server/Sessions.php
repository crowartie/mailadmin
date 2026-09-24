<?php

namespace App\Services\Server;

use App\Models\MailSession;
use Illuminate\Support\Facades\DB;

/**
 * Активные сеансы сотрудников: веб-почта (наша таблица mail_sessions) и IMAP/POP3 (doveadm who).
 */
class Sessions
{
    /** @return array<int,array{id:string,user:string,device:string,ip:string,seen:string,kind:string,me:bool}> */
    public function all(?string $onlyUser = null, ?string $currentSessionId = null): array
    {
        $out = [];
        $alive = DB::table('sessions')->pluck('id')->flip();
        $live = \App\Models\Vmail\Mailbox::query()->pluck('username')->map('strtolower')->flip();
        MailSession::query()->whereNotIn('user', $live->keys())->delete();
        $web = MailSession::query()->when($onlyUser, fn ($q) => $q->where('user', $onlyUser))->orderByDesc('last_seen_at')->get();
        // Одинаковые сеансы (тот же браузер, тот же адрес) сворачиваем в одну строку:
        // список нужен, чтобы заметить чужой вход, а из десятка «Веб-почта · Windows · Edge»
        // заметить уже ничего нельзя. Завершение такой строки закрывает всю группу.
        $groups = [];
        foreach ($web as $s) {
            if (! isset($alive[$s->id])) {
                $s->delete();
                continue;
            }
            $device = 'Веб-почта · ' . ($s->device ?: 'браузер') . ($s->impersonated ? ' · вход администратора' : '');
            $key = $s->user . '|' . $device . '|' . $s->ip;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'id' => 'web:' . $s->id,
                    'user' => $s->user,
                    'device' => $device,
                    'ip' => $s->ip,
                    'seen' => $s->last_seen_at?->toIso8601String(),
                    'kind' => 'web',
                    'me' => $s->id === $currentSessionId,
                    'count' => 1,
                ];
                continue;
            }
            $groups[$key]['id'] .= ',' . $s->id;
            $groups[$key]['count']++;
            $groups[$key]['me'] = $groups[$key]['me'] || $s->id === $currentSessionId;
        }
        $out = array_values($groups);
        // Запомненные устройства, у которых сеанс уже кончился: по ним войдут без пароля — их тоже видно и можно завершить.
        $withSession = array_flip(array_merge(...array_map(fn ($g) => explode(',', substr($g['id'], 4)), $out ?: [['id' => 'web:']])));
        foreach ($onlyUser ? \App\Services\Mail\RememberDevice::list($onlyUser) : [] as $r) {
            if ($r->session_id && isset($withSession[$r->session_id]) && isset($alive[$r->session_id])) {
                foreach ($out as &$g) {
                    if (in_array($r->session_id, explode(',', substr($g['id'], 4)), true)) {
                        $g['remembered'] = true;
                    }
                }
                unset($g);
                continue;
            }
            $out[] = ['id' => 'remember:' . $r->id, 'user' => $r->user, 'device' => 'Веб-почта · ' . ($r->device ?: 'браузер'), 'ip' => $r->ip,
                'seen' => $r->last_used_at ? \Illuminate\Support\Carbon::parse($r->last_used_at)->toIso8601String() : null, 'kind' => 'web', 'me' => false, 'count' => 1, 'remembered' => true];
        }
        foreach ($this->imap() as $row) {
            if ($onlyUser && $row['user'] !== $onlyUser) {
                continue;
            }
            $out[] = $row + ['me' => false];
        }

        // Список не должен превращаться в бесконечную ленту у давно работающего сотрудника.
        return array_slice($out, 0, 50);
    }

    /** @return array<int,array{id:string,user:string,device:string,ip:string,seen:?string,kind:string}> */
    public function imap(): array
    {
        [$code, $out] = Ctl::run('who', [], 10);
        if ($code !== 0) {
            return [];
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            // username  # proto (pids) (ips)
            if (! preg_match('/^(\S+@\S+)\s+(\d+)\s+(\w+)\s+\(([^)]*)\)\s+\(([^)]*)\)/', $line, $m)) {
                continue;
            }
            foreach (array_unique(preg_split('/\s+/', trim($m[5]))) as $ip) {
                $rows[] = ['id' => 'imap:' . $m[1] . ':' . $ip, 'user' => $m[1], 'device' => strtoupper($m[3]) . ' · ' . $m[2] . ' ' . ($m[2] === '1' ? 'соединение' : 'соединений') . ' (почтовая программа или телефон)', 'ip' => $ip, 'seen' => null, 'kind' => 'imap'];
            }
        }

        return $rows;
    }

    /** Завершить сеанс: веб — удалить сессию, IMAP — doveadm kick. */
    public function kick(string $id): void
    {
        if (str_starts_with($id, 'web:')) {
            // В свёрнутой строке идентификаторы перечислены через запятую.
            $sids = array_filter(explode(',', substr($id, 4)));
            DB::table('sessions')->whereIn('id', $sids)->delete();
            MailSession::query()->whereIn('id', $sids)->delete();
            \App\Services\Mail\RememberDevice::revokeSessions($sids);
        } elseif (str_starts_with($id, 'remember:')) {
            \App\Services\Mail\RememberDevice::revokeId((int) substr($id, 9));
        } elseif (str_starts_with($id, 'imap:')) {
            [, $user] = explode(':', $id, 3) + [null, null];
            if ($user) {
                Ctl::out('kick', [$user]);
            }
        }
    }

    public function kickAll(string $user): void
    {
        foreach (MailSession::query()->where('user', $user)->pluck('id') as $sid) {
            DB::table('sessions')->where('id', $sid)->delete();
        }
        MailSession::query()->where('user', $user)->delete();
        \App\Services\Mail\RememberDevice::revokeUser($user);
        Ctl::run('kick', [$user]);
    }
}
