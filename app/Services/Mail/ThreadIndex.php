<?php

namespace App\Services\Mail;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Индекс цепочек ответов в базе (таблицы mail_threads / mail_thread_refs / mail_thread_state).
 *
 * Строится один раз по заголовкам Message-ID, In-Reply-To и References, дальше пополняется:
 * планировщик раз в две минуты дочитывает новые письма по UIDNEXT каждой папки, а действия в веб-почте
 * (перенос, копия, удаление, отправка) обновляют затронутые папки сразу. Удалённые письма замечаются
 * по расхождению числа писем в папке с числом строк и при показе цепочки.
 */
final class ThreadIndex
{
    /** Папки, где цепочки не ищем: спам, корзина, чужие. */
    private const SKIP_ROLES = ['spam', 'trash', 'shared'];

    private const CHUNK = 500;

    /**
     * Дочитать новое (или перестроить) по папкам ящика. Один ящик обрабатывает один процесс:
     * планировщик и ручной запуск не должны вставлять одни и те же строки одновременно.
     *
     * @param  string[]  $onlyPaths  ограничиться этими папками
     * @return array{folders:int,added:int,removed:int,rebuilt:int}
     */
    public static function sync(MailStore $store, array $onlyPaths = [], bool $rebuild = false): array
    {
        $user = $store->user();
        $stats = ['folders' => 0, 'added' => 0, 'removed' => 0, 'rebuilt' => 0];
        $lock = Cache::lock('threads:' . $user, 1800);
        if (! $lock->get()) {
            return $stats;
        }
        try {
            // Для точечного обновления не перечисляем все папки (LIST + STATUS каждой): берём только названные.
            $list = $onlyPaths
                ? array_map(fn ($p) => ['path' => $p, 'role' => MailStore::roleOfPath($p)], $onlyPaths)
                : $store->folders();
            foreach ($list as $f) {
                if (in_array($f['role'], self::SKIP_ROLES, true) || str_starts_with($f['path'], MailStore::SHARED_PREFIX)) {
                    continue;
                }
                $path = $f['path'];
                $st = $store->folderStatus($path);
                if (! isset($st['uidnext'])) {
                    continue;
                }
                $stats['folders']++;
                $uidvalidity = (int) ($st['uidvalidity'] ?? 0);
                $uidnext = (int) $st['uidnext'];
                $messages = (int) ($st['messages'] ?? 0);
                $state = DB::table('mail_thread_state')->where('user', $user)->where('folder', $path)->first();

                if ($rebuild || ! $state || (int) $state->uidvalidity !== $uidvalidity) {
                    self::forgetFolder($user, $path);
                    $uids = $messages > 0 ? $store->searchAll($path) : [];
                    $stats['rebuilt']++;
                } else {
                    $uids = $uidnext > (int) $state->uidnext ? $store->searchFrom($path, (int) $state->uidnext) : [];
                    $known = (int) DB::table('mail_threads')->where('user', $user)->where('folder', $path)->count();
                    if ($known + count($uids) !== $messages) {
                        // что-то удалили или переложили мимо нас — сверяем список UID целиком (дёшево: один SEARCH)
                        $all = $store->searchAll($path);
                        $have = DB::table('mail_threads')->where('user', $user)->where('folder', $path)->pluck('uid')->map(fn ($u) => (int) $u)->all();
                        $gone = array_diff($have, $all);
                        if ($gone) {
                            self::forget($user, $path, $gone);
                            $stats['removed'] += count($gone);
                        }
                        $uids = array_values(array_diff($all, $have));
                    }
                }
                $stats['added'] += self::indexUids($store, $user, $path, $uids);
                DB::table('mail_thread_state')->updateOrInsert(
                    ['user' => $user, 'folder' => $path],
                    ['uidvalidity' => $uidvalidity, 'uidnext' => $uidnext, 'updated_at' => now()],
                );
            }
        } finally {
            $lock->release();
        }

        return $stats;
    }

    /** Обновить индекс после действия в веб-почте (перенос, удаление, отправка). Ошибки глотаем: индекс — не источник правды. */
    public static function touch(MailStore $store, array $paths): void
    {
        try {
            self::sync($store, array_values(array_unique(array_filter($paths))));
        } catch (\Throwable) {
        }
    }

    /**
     * Участники цепочки письма, кроме него самого: [['folder' => …, 'uid' => …], …] по дате.
     * null — письмо не в индексе (папка ещё не проиндексирована): вызывающий ищет по-старому.
     *
     * @return array<int,array{folder:string,uid:int}>|null
     */
    public static function threadOf(string $user, string $folder, int $uid, int $limit = 30): ?array
    {
        $row = DB::table('mail_threads')->where('user', $user)->where('folder', $folder)->where('uid', $uid)->first();
        if (! $row) {
            return null;
        }

        return DB::table('mail_threads')->where('user', $user)->where('thread_id', $row->thread_id)
            ->where(fn ($q) => $q->where('folder', '!=', $folder)->orWhere('uid', '!=', $uid))
            ->orderBy('date')->limit($limit)->get()
            ->map(fn ($r) => ['folder' => (string) $r->folder, 'uid' => (int) $r->uid])->all();
    }

    public static function forget(string $user, string $folder, array $uids): void
    {
        if ($uids) {
            DB::table('mail_threads')->where('user', $user)->where('folder', $folder)->whereIn('uid', $uids)->delete();
        }
    }

    public static function forgetFolder(string $user, string $folder): void
    {
        DB::table('mail_threads')->where('user', $user)->where('folder', $folder)->delete();
        DB::table('mail_thread_state')->where('user', $user)->where('folder', $folder)->delete();
    }

    public static function forgetUser(string $user): void
    {
        foreach (['mail_threads', 'mail_thread_refs', 'mail_thread_state'] as $t) {
            DB::table($t)->where('user', $user)->delete();
        }
    }

    /** Проиндексировать письма папки по UID. @return int сколько добавлено */
    public static function indexUids(MailStore $store, string $user, string $path, array $uids): int
    {
        $n = 0;
        foreach (array_chunk(array_values(array_map('intval', $uids)), self::CHUNK) as $chunk) {
            $headers = $store->rawHeaders($path, $chunk);
            $items = [];
            foreach ($chunk as $uid) {
                $items[$uid] = self::parse((string) ($headers[$uid] ?? ''));
            }
            self::addMany($user, $path, $items);
            $n += count($items);
        }

        return $n;
    }

    /**
     * Пачка писем одной папки: цепочки определяются по ссылкам в обе стороны (на кого ссылаемся и кто ссылается на нас),
     * связи внутри пачки — в памяти, с базой — двумя выборками на пачку; расходящиеся цепочки сливаются.
     *
     * @param  array<int,array{id:string,refs:string[],date:?Carbon}>  $items  uid => заголовки
     */
    public static function addMany(string $user, string $folder, array $items): void
    {
        if (! $items) {
            return;
        }
        $mids = [];
        $lookup = [];
        foreach ($items as $it) {
            if ($it['id'] !== '') {
                $mids[$it['id']] = true;
                $lookup[$it['id']] = true;
            }
            foreach ($it['refs'] as $r) {
                $lookup[$r] = true;
            }
        }
        // Известные цепочки: по Message-ID (наши же письма в других папках и те, на кого ссылаемся) и по обратным ссылкам.
        $known = [];      // message_id|ref → [thread_id => true]
        foreach (array_chunk(array_keys($lookup), self::CHUNK) as $part) {
            foreach (DB::table('mail_threads')->where('user', $user)->whereIn('message_id', $part)->get(['message_id', 'thread_id']) as $r) {
                $known[$r->message_id][$r->thread_id] = true;
            }
        }
        foreach (array_chunk(array_keys($mids), self::CHUNK) as $part) {
            foreach (DB::table('mail_thread_refs')->where('user', $user)->whereIn('ref_id', $part)->get(['ref_id', 'thread_id']) as $r) {
                $known[$r->ref_id][$r->thread_id] = true;
            }
        }

        // Система непересекающихся множеств по идентификаторам цепочек.
        $parent = [];
        $find = function (string $t) use (&$parent, &$find): string {
            while (isset($parent[$t]) && $parent[$t] !== $t) {
                $parent[$t] = $parent[$parent[$t]] ?? $parent[$t];
                $t = $parent[$t];
            }

            return $t;
        };
        $union = function (string $a, string $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        $rows = [];
        $refRows = [];
        $batchMid = [];   // message_id → thread (в этой пачке)
        $batchRef = [];   // ref → [thread] (кто в пачке ссылается на ref)
        $dbThreads = [];  // цепочки из базы, которые могли слиться
        foreach ($items as $uid => $it) {
            $id = $it['id'];
            $refs = array_values(array_unique(array_filter($it['refs'], fn ($r) => $r !== '' && $r !== $id)));
            $cands = [];
            foreach (array_merge($id !== '' ? [$id] : [], $refs) as $key) {
                foreach (array_keys($known[$key] ?? []) as $t) {
                    $cands[$t] = true;
                    $dbThreads[$t] = true;
                }
                if (isset($batchMid[$key])) {
                    $cands[$batchMid[$key]] = true;
                }
            }
            if ($id !== '') {
                foreach ($batchRef[$id] ?? [] as $t) {
                    $cands[$t] = true;
                }
            }
            $cands = array_keys($cands);
            $thread = $cands[0] ?? ($id !== '' ? $id : 'uid:' . $folder . '#' . $uid);
            $parent[$thread] ??= $thread;
            foreach ($cands as $c) {
                $parent[$c] ??= $c;
                $union($thread, $c);
            }
            if ($id !== '') {
                $batchMid[$id] = $thread;
            }
            foreach ($refs as $r) {
                $batchRef[$r][] = $thread;
            }
            $rows[] = ['uid' => (int) $uid, 'id' => $id, 'thread' => $thread, 'refs' => $refs, 'date' => $it['date']];
        }

        // Слияния: всё, что попало в одно множество с цепочкой из базы, переименовываем в её корень.
        $renames = [];
        foreach (array_keys($dbThreads) as $t) {
            $root = $find($t);
            if ($root !== $t) {
                $renames[$root][] = $t;
            }
        }
        foreach ($renames as $root => $olds) {
            DB::table('mail_threads')->where('user', $user)->whereIn('thread_id', $olds)->update(['thread_id' => mb_substr($root, 0, 255)]);
            DB::table('mail_thread_refs')->where('user', $user)->whereIn('thread_id', $olds)->update(['thread_id' => mb_substr($root, 0, 255)]);
        }

        $insert = [];
        foreach ($rows as $r) {
            $thread = mb_substr($find($r['thread']), 0, 255);
            $insert[] = ['user' => $user, 'folder' => $folder, 'uid' => $r['uid'], 'message_id' => mb_substr($r['id'], 0, 255), 'thread_id' => $thread, 'date' => $r['date']];
            foreach ($r['refs'] as $ref) {
                $refRows[] = ['user' => $user, 'ref_id' => mb_substr($ref, 0, 255), 'thread_id' => $thread];
            }
        }
        foreach (array_chunk($insert, self::CHUNK) as $part) {
            DB::table('mail_threads')->upsert($part, ['user', 'folder', 'uid'], ['message_id', 'thread_id', 'date']);
        }
        foreach (array_chunk($refRows, 1000) as $part) {
            DB::table('mail_thread_refs')->insert($part);
        }
    }

    /** Message-ID, ссылки и дата из сырых заголовков. @return array{id:string,refs:string[],date:?Carbon} */
    public static function parse(string $raw): array
    {
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $raw);
        $field = function (string $name) use ($unfolded): string {
            return preg_match('/^' . $name . ':[ \t]*(.*)$/mi', $unfolded, $m) ? trim($m[1]) : '';
        };
        $ids = fn (string $v) => preg_match_all('/<([^<>\s]+)>/', $v, $m) ? $m[1] : [];
        $id = $ids($field('Message-ID'))[0] ?? '';
        $refs = array_merge($ids($field('In-Reply-To')), $ids($field('References')));
        $date = null;
        if (($d = $field('Date')) !== '') {
            try {
                $date = Carbon::parse($d)->utc();
            } catch (\Throwable) {
                $date = null;
            }
        }

        return ['id' => $id, 'refs' => $refs, 'date' => $date];
    }
}
