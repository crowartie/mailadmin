<?php

namespace App\Services\Mail;

use App\Models\Vmail\Mailbox;
use App\Models\Webmail\SharedRead;
use Illuminate\Support\Facades\Cache;

/**
 * «Кто прочитал» в общих папках (обращение №51).
 *
 * У письма в чужом ящике теперь у каждого свой флаг «прочитано» (Dovecot INDEXPVT), а кто именно
 * из коллег его открывал — хранится здесь: чей ящик, Message-ID, кто, когда. Пишется при открытии
 * письма в папке Shared/… и при действии «Прочитано»; «Непрочитано» отметку снимает. Показывается
 * кружками в списке и строкой «Прочитали» в письме. Хранится год.
 */
class SharedReads
{
    public const KEEP_DAYS = 365;

    /** Владелец общей папки по её пути или null, если папка своя. */
    public static function ownerOf(string $path): ?string
    {
        return FolderTree::sharedOwner($path);
    }

    /** Путь той же папки в ящике владельца: «Shared/info@x/Закупки» → «Закупки», корень общего ящика → INBOX. */
    public static function ownerPath(string $path): string
    {
        $owner = self::ownerOf($path);
        if ($owner === null) {
            return $path;
        }
        $rest = substr($path, strlen(FolderTree::SHARED_PREFIX . $owner . '/'));

        return $rest === false || $rest === '' ? 'INBOX' : $rest;
    }

    /** Ключ записи: Message-ID без скобок, не длиннее колонки. Пустой — писать нечего. */
    public static function key(?string $messageId): string
    {
        return mb_substr(trim((string) $messageId, " \t<>"), 0, 190);
    }

    public static function record(string $owner, ?string $messageId, string $user): void
    {
        $key = self::key($messageId);
        $owner = strtolower($owner);
        $user = strtolower($user);
        if ($key === '' || $owner === '' || $user === '' || $owner === $user) {
            return;
        }
        // Первое открытие важнее последнего: «когда прочитал» — это когда увидел впервые.
        SharedRead::query()->firstOrCreate(['owner' => $owner, 'message_id' => $key, 'user' => $user], ['read_at' => now()]);
    }

    public static function forget(string $owner, ?string $messageId, string $user): void
    {
        $key = self::key($messageId);
        if ($key === '') {
            return;
        }
        SharedRead::query()->where(['owner' => strtolower($owner), 'message_id' => $key, 'user' => strtolower($user)])->delete();
    }

    /**
     * Кто прочитал каждое из писем: Message-ID → [{mail, name, at}], по времени.
     * Один запрос на страницу списка.
     * @param array<int,string|null> $messageIds
     * @return array<string,array<int,array{mail:string,name:string,at:string}>>
     */
    public static function forMessages(string $owner, array $messageIds): array
    {
        $keys = array_values(array_unique(array_filter(array_map([self::class, 'key'], $messageIds))));
        if (! $keys) {
            return [];
        }
        $rows = SharedRead::query()->where('owner', strtolower($owner))->whereIn('message_id', $keys)->orderBy('read_at')->get();
        $names = self::names($rows->pluck('user')->unique()->all());
        $out = [];
        foreach ($rows as $r) {
            $out[$r->message_id][] = ['mail' => $r->user, 'name' => $names[$r->user] ?? $r->user, 'at' => $r->read_at?->toIso8601String() ?? ''];
        }

        return $out;
    }

    /**
     * Кто из имеющих доступ к папке ещё не читал письмо: по ACL папки (владелец не в счёт).
     * @param array<int,array{mail:string}> $readers
     * @return array<int,array{mail:string,name:string}>
     */
    public static function notRead(string $owner, string $imapPath, array $readers): array
    {
        $have = array_map(fn ($r) => strtolower($r['mail']), $readers);
        $out = [];
        foreach (self::people($owner, $imapPath) as $mail => $name) {
            if (! in_array($mail, $have, true)) {
                $out[] = ['mail' => $mail, 'name' => $name];
            }
        }

        return $out;
    }

    /** Кому открыта папка ящика: mail → имя. Считается по ACL раз в 10 минут — права меняются редко. */
    public static function people(string $owner, string $imapPath): array
    {
        $owner = strtolower($owner);

        return Cache::remember('shared-people.' . $owner . '.' . $imapPath, 600, function () use ($owner, $imapPath) {
            try {
                $mails = array_keys(app(FolderShares::class)->levels($owner, $imapPath));
            } catch (\Throwable) {
                return [];
            }

            return self::names($mails);
        });
    }

    /** Имена сотрудников по адресам (без служебных и заблокированных — они писем не читают). */
    private static function names(array $mails): array
    {
        if (! $mails) {
            return [];
        }
        $out = [];
        try {
            foreach (Mailbox::query()->people()->whereIn('username', $mails)->get(['username', 'name']) as $m) {
                $out[strtolower($m->username)] = $m->name ?: $m->username;
            }
        } catch (\Throwable) {
            // Без базы ящиков (тесты) — имена равны адресам.
            foreach ($mails as $m) {
                $out[strtolower($m)] = $m;
            }
        }

        return $out;
    }

    /** Отметки старше года — долой (команда shared-reads:purge, раз в сутки). */
    public static function purge(): int
    {
        return SharedRead::query()->where('read_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    /**
     * Строка «Прочитали» словами — для приложения и для тестов: «Аносов М., Мусин Е.» или «ещё никто».
     * @param array<int,array{name:string}> $readers
     */
    public static function label(array $readers): string
    {
        if (! $readers) {
            return 'ещё никто';
        }
        $names = array_map(fn ($r) => self::shortName($r['name']), $readers);
        if (count($names) > 4) {
            return implode(', ', array_slice($names, 0, 3)) . ' и ещё ' . (count($names) - 3);
        }

        return implode(', ', $names);
    }

    /** «Аносов Михаил Леонидович» → «Аносов М.»; адрес — как есть. */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) < 2 || str_contains($name, '@')) {
            return trim($name);
        }

        return $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.';
    }
}
