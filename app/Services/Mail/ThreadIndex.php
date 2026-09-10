<?php

namespace App\Services\Mail;

use Carbon\Carbon;
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
     * Дочитать новое (или перестроить) по папкам ящика.
     *
     * @param  string[]  $onlyPaths  ограничиться этими папками
     * @return array{folders:int,added:int,removed:int,rebuilt:int}
     */
    public static function sync(MailStore $store, array $onlyPaths = [], bool $rebuild = false): array
    {
        $user = $store->user();
        $stats = ['folders' => 0, 'added' => 0, 'removed' => 0, 'rebuilt' => 0];
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
            foreach ($chunk as $uid) {
                $h = self::parse((string) ($headers[$uid] ?? ''));
                self::add($user, $path, $uid, $h['id'], $h['refs'], $h['date']);
                $n++;
            }
        }

        return $n;
    }

    /** Одно письмо: определить цепочку по ссылкам (в обе стороны), при необходимости слить цепочки. */
    public static function add(string $user, string $folder, int $uid, string $messageId, array $refs, ?Carbon $date): void
    {
        $refs = array_values(array_unique(array_filter($refs, fn ($r) => $r !== '' && $r !== $messageId)));
        $threads = [];
        $lookup = $messageId !== '' ? array_merge([$messageId], $refs) : $refs;
        if ($lookup) {
            // письма, на которые ссылаемся (или наш же Message-ID в другой папке)
            foreach (DB::table('mail_threads')->where('user', $user)->whereIn('message_id', $lookup)->distinct()->pluck('thread_id') as $t) {
                $threads[$t] = true;
            }
        }
        if ($messageId !== '') {
            // письма, которые ссылаются на нас (ответы, проиндексированные раньше нас)
            foreach (DB::table('mail_thread_refs')->where('user', $user)->where('ref_id', $messageId)->distinct()->pluck('thread_id') as $t) {
                $threads[$t] = true;
            }
        }
        $threads = array_keys($threads);
        $thread = $threads[0] ?? ($messageId !== '' ? $messageId : 'uid:' . $folder . '#' . $uid);
        if (count($threads) > 1) {
            $others = array_slice($threads, 1);
            DB::table('mail_threads')->where('user', $user)->whereIn('thread_id', $others)->update(['thread_id' => $thread]);
            DB::table('mail_thread_refs')->where('user', $user)->whereIn('thread_id', $others)->update(['thread_id' => $thread]);
        }
        DB::table('mail_threads')->updateOrInsert(
            ['user' => $user, 'folder' => $folder, 'uid' => $uid],
            ['message_id' => mb_substr($messageId, 0, 255), 'thread_id' => mb_substr($thread, 0, 255), 'date' => $date],
        );
        if ($refs) {
            DB::table('mail_thread_refs')->insert(array_map(fn ($r) => ['user' => $user, 'ref_id' => mb_substr($r, 0, 255), 'thread_id' => mb_substr($thread, 0, 255)], $refs));
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
