<?php

namespace App\Services\Mail;

use App\Models\Vmail\Mailbox;
use App\Services\Server\Ctl;

/**
 * Общий доступ к папкам ящика (как в Kerio): права Dovecot ACL через doveadm.
 * Читатель видит письма и может помечать их прочитанными; редактор ещё перекладывает, удаляет, кладёт свои.
 * У получателя папка появляется в пространстве Shared/<владелец>/<папка>.
 */
class FolderShares
{
    public const LEVELS = [
        'reader' => ['lookup', 'read', 'write-seen'],
        'editor' => ['lookup', 'read', 'write', 'write-seen', 'write-deleted', 'insert', 'post', 'expunge', 'create', 'delete'],
    ];

    /** Имя папки для doveadm — UTF-8 (в API папки ходят в modified UTF-7). */
    public static function utf8(string $imapPath): string
    {
        return mb_convert_encoding($imapPath, 'UTF-8', 'UTF7-IMAP') ?: $imapPath;
    }

    /** @return array<int,array{mail:string,name:string,level:string}> */
    public function list(string $owner, string $folderUtf8): array
    {
        [$code, $out] = Ctl::run('acl-get', [strtolower($owner), $folderUtf8], 20);
        if ($code !== 0) {
            return [];
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            if (! preg_match('/^user=(\S+)\s+(.*)$/', trim($line), $m)) {
                continue;
            }
            $rights = preg_split('/\s+/', trim($m[2]));
            $rows[] = ['mail' => strtolower($m[1]), 'level' => in_array('insert', $rights, true) ? 'editor' : 'reader'];
        }
        $names = Mailbox::query()->whereIn('username', array_column($rows, 'mail'))->pluck('name', 'username');
        foreach ($rows as &$r) {
            $r['name'] = $names[$r['mail']] ?: $r['mail'];
        }

        return $rows;
    }

    public function set(string $owner, string $folderUtf8, string $with, string $level): void
    {
        $with = strtolower(trim($with));
        if (! isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException('Уровень доступа: reader или editor');
        }
        if ($with === strtolower($owner)) {
            throw new \InvalidArgumentException('Это ваш собственный ящик');
        }
        if (! Mailbox::query()->where('username', $with)->where('active', 1)->exists()) {
            throw new \InvalidArgumentException('Такого сотрудника нет');
        }
        // doveadm acl set добавляет права к существующим — сначала снимаем, чтобы «читатель» не остался редактором.
        Ctl::run('acl-delete', [strtolower($owner), $folderUtf8, $with], 20);
        Ctl::out('acl-set', array_merge([strtolower($owner), $folderUtf8, $with], self::LEVELS[$level]), 30);
    }

    public function remove(string $owner, string $folderUtf8, string $with): void
    {
        Ctl::out('acl-delete', [strtolower($owner), $folderUtf8, strtolower(trim($with))], 20);
    }

    /** Кому можно открыть доступ: активные сотрудники, кроме владельца. @return array<int,array{mail:string,name:string}> */
    public static function candidates(string $owner): array
    {
        return Mailbox::query()->people()->where('username', '!=', strtolower($owner))->orderBy('name')->get(['username', 'name'])
            ->map(fn ($m) => ['mail' => $m->username, 'name' => $m->name ?: $m->username])->all();
    }
}
