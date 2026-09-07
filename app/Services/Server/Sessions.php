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
        $web = MailSession::query()->when($onlyUser, fn ($q) => $q->where('user', $onlyUser))->orderByDesc('last_seen_at')->get();
        foreach ($web as $s) {
            if (! isset($alive[$s->id])) {
                $s->delete();
                continue;
            }
            $out[] = [
                'id' => 'web:' . $s->id,
                'user' => $s->user,
                'device' => 'Веб-почта · ' . ($s->device ?: 'браузер') . ($s->impersonated ? ' · вход администратора' : ''),
                'ip' => $s->ip,
                'seen' => $s->last_seen_at?->toIso8601String(),
                'kind' => 'web',
                'me' => $s->id === $currentSessionId,
            ];
        }
        foreach ($this->imap() as $row) {
            if ($onlyUser && $row['user'] !== $onlyUser) {
                continue;
            }
            $out[] = $row + ['me' => false];
        }

        return $out;
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
            $sid = substr($id, 4);
            DB::table('sessions')->where('id', $sid)->delete();
            MailSession::query()->where('id', $sid)->delete();
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
        Ctl::run('kick', [$user]);
    }
}
