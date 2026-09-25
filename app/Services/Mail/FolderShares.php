<?php

namespace App\Services\Mail;

use App\Models\Vmail\Mailbox;
use App\Services\Server\Ctl;
use Illuminate\Support\Facades\Log;

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
        // Владелец: всё, что редактор, плюс пишет от имени ящика (только для «Входящих»). В ACL отличается правом admin.
        'owner' => ['lookup', 'read', 'write', 'write-seen', 'write-deleted', 'insert', 'post', 'expunge', 'create', 'delete', 'admin'],
    ];

    public const TITLES = ['reader' => 'читатель', 'editor' => 'редактор', 'owner' => 'владелец'];

    /** Уровень по набору прав Dovecot. */
    public static function levelOf(array $rights): string
    {
        if (in_array('admin', $rights, true)) {
            return 'owner';
        }

        return in_array('insert', $rights, true) ? 'editor' : 'reader';
    }

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
            $rows[] = ['mail' => strtolower($m[1]), 'level' => self::levelOf($rights)];
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
            throw new \InvalidArgumentException('Уровень доступа: reader, editor или owner');
        }
        if ($level === 'owner' && strtoupper($folderUtf8) !== 'INBOX') {
            throw new \InvalidArgumentException('Владельцем можно сделать только по «Входящим» — это доступ ко всему ящику');
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
        if (strtoupper($folderUtf8) === 'INBOX') {
            // Доступ по «Входящим» — это доступ к ящику: владелец получает все его папки, редактор — системные
            // («Спам», «Корзина», «Отправленные», «Черновики», «Архив», «Рассылки»), читателю остальные не нужны.
            // Сначала снимаем везде (понижение уровня), потом ставим где положено.
            try {
                $store = new MailStore(ImapSession::master($owner));
                foreach (self::allFolders($store) as $path) {
                    Ctl::run('acl-delete', [strtolower($owner), $path, $with], 20);
                }
                foreach (self::targetFolders($store, $level) as $path) {
                    Ctl::out('acl-set', array_merge([strtolower($owner), $path, $with], self::LEVELS[$level]), 30);
                }
            } catch (\Throwable $e) {
                Log::warning('Права на папки ящика не разложены', ['owner' => $owner, 'with' => $with, 'error' => $e->getMessage()]);
            }
        }
        self::forgetCaches($owner, $with);
    }

    /**
     * Один уровень на все папки ящика: «читать всё, но без права писать от имени ящика».
     * Владелец по «Входящим» и так получает все папки — для него это обычный set('INBOX').
     * Порядок важен: set('INBOX') снимает права со всех остальных папок (понижение уровня),
     * поэтому папки раздаются уже после него. Возвращает, сколько папок открыто.
     */
    public function setAll(string $owner, string $with, string $level): int
    {
        $this->set($owner, 'INBOX', $with, $level);
        if ($level === 'owner') {
            return 0;
        }
        $store = new MailStore(ImapSession::master($owner));
        $n = 1;
        foreach (self::allFolders($store) as $path) {
            Ctl::run('acl-delete', [strtolower($owner), $path, strtolower(trim($with))], 20);
            Ctl::out('acl-set', array_merge([strtolower($owner), $path, strtolower(trim($with))], self::LEVELS[$level]), 30);
            $n++;
        }
        self::forgetCaches($owner, $with);

        return $n;
    }

    /** Все свои папки ящика, кроме «Входящих» (UTF-8). */
    private static function allFolders(MailStore $store): array
    {
        $out = [];
        foreach ($store->folders() as $f) {
            if ($f['role'] !== 'shared' && strtoupper($f['path']) !== 'INBOX') {
                $out[] = self::utf8($f['path']);
            }
        }

        return $out;
    }

    /** Папки, которые полагаются уровню доступа по «Входящим»: владельцу — все, редактору — системные. */
    private static function targetFolders(MailStore $store, string $level): array
    {
        if ($level === 'reader') {
            return [];
        }
        if ($level === 'owner') {
            return self::allFolders($store);
        }
        $out = [];
        foreach (['spam', 'trash'] as $role) {
            $out[] = self::utf8($store->rolePath($role));   // создаются, если их ещё нет
        }
        foreach ($store->folders() as $f) {
            if (in_array($f['role'], ['sent', 'drafts', 'archive', 'lists'], true)) {
                $out[] = self::utf8($f['path']);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Доложить права по папкам, появившимся после выдачи доступа (новая «Рассылки», «Архив», папка владельца).
     * Ничего не снимает. Возвращает список «папка → кому», что пришлось доложить.
     */
    public function sync(string $owner): array
    {
        $owner = strtolower($owner);
        $done = [];
        $store = new MailStore(ImapSession::master($owner));
        $inbox = self::aclLevels($store, 'INBOX');
        foreach ($inbox as $with => $level) {
            if ($level === 'reader') {
                continue;
            }
            foreach (self::targetFolders($store, $level) as $utf8) {
                $imapPath = mb_convert_encoding($utf8, 'UTF7-IMAP', 'UTF-8') ?: $utf8;
                $have = self::aclLevels($store, $imapPath)[$with] ?? null;
                if ($have === $level) {
                    continue;
                }
                Ctl::run('acl-delete', [$owner, $utf8, $with], 20);
                Ctl::out('acl-set', array_merge([$owner, $utf8, $with], self::LEVELS[$level]), 30);
                $done[] = $utf8 . ' → ' . $with . ' (' . self::TITLES[$level] . ')';
                self::forgetCaches($owner, $with);
            }
        }

        return $done;
    }

    /**
     * Все общие папки всех ящиков (страница «Общий доступ»): по каждому владельцу из share_folder — его папки и кому
     * они открыты. Через IMAP GETACL мастер-сессией: на ящик уходит десятки миллисекунд.
     * @return array<int,array{owner:string,ownerName:string,folder:string,folderName:string,role:string,with:string,withName:string,level:string}>
     */
    public function overview(): array
    {
        $owners = \Illuminate\Support\Facades\DB::connection('vmail')->table('share_folder')->distinct()->pluck('from_user')->map('strtolower')->sort()->values();
        $names = Mailbox::query()->pluck('name', 'username');
        $rows = [];
        foreach ($owners as $owner) {
            try {
                $store = new MailStore(ImapSession::master($owner));
                foreach ($store->folders() as $f) {
                    if ($f['role'] === 'shared') {
                        continue;
                    }
                    foreach (self::aclLevels($store, $f['path']) as $with => $level) {
                        $rows[] = [
                            'owner' => $owner, 'ownerName' => $names[$owner] ?: $owner,
                            'folder' => $f['path'], 'folderName' => $f['name'], 'role' => $f['role'],
                            'with' => $with, 'withName' => $names[$with] ?? $with, 'level' => $level,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $rows[] = ['owner' => $owner, 'ownerName' => $names[$owner] ?: $owner, 'folder' => '', 'folderName' => '', 'role' => 'error', 'with' => '', 'withName' => '', 'level' => mb_substr($e->getMessage(), 0, 120)];
            }
        }

        return $rows;
    }

    /** Кому и с каким уровнем открыта папка — через IMAP GETACL (быстро, без doveadm). @return array<string,string> */
    private static function aclLevels(MailStore $store, string $imapPath): array
    {
        $conn = $store->client()->getConnection();
        $out = [];
        foreach ((array) $conn->requestAndResponse('GETACL', [$conn->escapeString($imapPath)])->data() as $line) {
            if (! is_array($line) || strtoupper((string) ($line[0] ?? '')) !== 'ACL') {
                continue;
            }
            for ($k = 2; $k + 1 < count($line); $k += 2) {
                $id = strtolower((string) $line[$k]);
                $r = (string) $line[$k + 1];
                if ($id === $store->user() || ! str_contains($id, '@') || $id[0] === '-') {
                    continue;
                }
                $out[$id] = str_contains($r, 'a') ? 'owner' : (str_contains($r, 'i') ? 'editor' : 'reader');
            }
        }

        return $out;
    }

    /** Список «от имени кого писать» и пометка «открыта коллегам» кэшируются — сбросить после смены прав. */
    private static function forgetCaches(string $owner, string $with): void
    {
        \Illuminate\Support\Facades\Cache::forget('sendas.' . strtolower(trim($with)));
        \Illuminate\Support\Facades\Cache::forget('shares-any.' . strtolower($owner));
    }

    public function remove(string $owner, string $folderUtf8, string $with): void
    {
        Ctl::out('acl-delete', [strtolower($owner), $folderUtf8, strtolower(trim($with))], 20);
        if (strtoupper($folderUtf8) === 'INBOX') {
            try {
                foreach (self::allFolders(new MailStore(ImapSession::master($owner))) as $path) {
                    Ctl::run('acl-delete', [strtolower($owner), $path, strtolower(trim($with))], 20);
                }
            } catch (\Throwable $e) {
                Log::warning('Права на папки ящика не сняты', ['owner' => $owner, 'with' => $with, 'error' => $e->getMessage()]);
            }
        }
        self::forgetCaches($owner, $with);
    }

    /** Кому можно открыть доступ: активные сотрудники, кроме владельца. @return array<int,array{mail:string,name:string}> */
    public static function candidates(string $owner): array
    {
        return Mailbox::query()->people()->where('username', '!=', strtolower($owner))->orderBy('name')->get(['username', 'name'])
            ->map(fn ($m) => ['mail' => $m->username, 'name' => $m->name ?: $m->username])->all();
    }
}
