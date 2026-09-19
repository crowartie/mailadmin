<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;

/**
 * Дерево папок ящика: список с ролями и счётчиками, общие папки коллег,
 * создание, переименование, удаление, место в ящике.
 *
 * Список папок нужен почти каждому запросу, поэтому он считается один раз
 * на соединение и лежит здесь же; всё, что дерево меняет, кэш и сбрасывает.
 */
class FolderTree
{
    /** Роли системных папок по именам, которые создаёт Dovecot в iRedMail. */
    private const ROLES = [
        'INBOX' => 'inbox', 'DRAFTS' => 'drafts', 'SENT' => 'sent', 'SENT ITEMS' => 'sent', 'SENT MESSAGES' => 'sent',
        'JUNK' => 'spam', 'SPAM' => 'spam', 'TRASH' => 'trash', 'DELETED ITEMS' => 'trash', 'ARCHIVE' => 'archive', 'SNOOZED' => 'snoozed', 'NEWSLETTERS' => 'lists',
    ];

    private const TITLES = [
        'inbox' => 'Входящие', 'drafts' => 'Черновики', 'sent' => 'Отправленные', 'spam' => 'Спам',
        'trash' => 'Корзина', 'archive' => 'Архив', 'snoozed' => 'Отложенные', 'lists' => 'Рассылки',
    ];

    private const ORDER = ['inbox' => 0, 'snoozed' => 1, 'drafts' => 2, 'sent' => 3, 'archive' => 4, 'lists' => 5, 'spam' => 6, 'trash' => 7, 'shared' => 20];

    /** Пространство общих папок Dovecot (namespace shared, prefix Shared/%%u/). */
    public const SHARED_PREFIX = 'Shared/';

    private ?array $folderCache = null;

    /** Счётчики по всем папкам, полученные одной командой (см. statusAll). */
    private ?array $statusCache = null;

    /** Строка возможностей сервера: спрашиваем один раз за соединение. */
    private ?string $capsCache = null;

    public function __construct(private readonly Client $client)
    {
    }

    /** Список папок придётся собрать заново (папку создали, переименовали или удалили). */
    public function forgetCache(): void
    {
        $this->folderCache = null;
        $this->statusCache = null;
    }

    /** Чей это ящик (в режиме администратора логин вида user*master — берём часть до звёздочки). */
    public function user(): string
    {
        $u = (string) $this->client->username;

        return strtolower(strstr($u, '*', true) ?: $u);
    }

    /** STATUS папки: uidvalidity, uidnext, messages, unseen (пусто, если папки нет). */
    public function folderStatus(string $path): array
    {
        try {
            return $this->safeStatus($this->folder($path));
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Папки ────────────────────────────────────────────────────────────

    /** @return array<int,array{path:string,name:string,role:string,depth:int,unread:int,total:int,parent:?string}> */
    public function folders(): array
    {
        if ($this->folderCache !== null) {
            return $this->folderCache;
        }

        $out = [];
        $owners = [];
        $walk = function ($folders, int $depth, ?string $parent) use (&$walk, &$out, &$owners) {
            foreach ($folders as $f) {
                /** @var Folder $f */
                if (str_starts_with($f->path, self::SHARED_PREFIX) || $f->path === rtrim(self::SHARED_PREFIX, '/')) {
                    // Чужая папка, открытая нам: Shared/<владелец>/<путь>. Корень и узел владельца не показываем.
                    $parts = explode('/', $f->path);
                    // mail_shared_explicit_inbox = no: узел владельца Shared/<owner> — это и есть его «Входящие».
                    if (count($parts) === 2 && empty($f->no_select)) {
                        $status = $this->safeStatus($f);
                        if ($status !== []) {
                            $owner = strtolower($parts[1]);
                            $owners[$owner] = true;
                            $out[] = ['path' => $f->path, 'name' => self::TITLES['inbox'], 'role' => 'shared', 'srole' => 'inbox', 'depth' => 0, 'parent' => null, 'owner' => $owner, 'inbox' => true, 'unread' => (int) ($status['unseen'] ?? 0), 'total' => (int) ($status['messages'] ?? 0)];
                        }
                    }
                    if (count($parts) >= 3 && ! (count($parts) === 3 && strtoupper($parts[2]) === 'INBOX')) {
                        $owner = strtolower($parts[1]);
                        $rel = array_slice($parts, 2);
                        $leaf = Mime::utf8Name(end($rel));
                        $role = count($rel) === 1 ? (self::ROLES[strtoupper($leaf)] ?? null) : null;
                        $status = $this->safeStatus($f);
                        $owners[$owner] = true;
                        $out[] = [
                            'path' => $f->path,
                            'name' => $role ? self::TITLES[$role] : $leaf,
                            'role' => 'shared',
                            'srole' => $role ?: 'custom',   // какая это папка у владельца: spam, trash, sent…
                            'depth' => count($rel) - 1,
                            'parent' => count($rel) > 1 ? $parent : null,
                            'owner' => $owner,
                            'unread' => (int) ($status['unseen'] ?? 0),
                            'total' => (int) ($status['messages'] ?? 0),
                        ];
                    }
                    if ($f->hasChildren()) {
                        $walk($f->children, $depth + 1, $f->path);
                    }
                    continue;
                }
                $role = self::ROLES[strtoupper($f->full_name)] ?? 'custom';
                $status = $this->safeStatus($f);
                $out[] = [
                    'path' => $f->path,
                    'name' => $role === 'custom' ? $f->name : self::TITLES[$role],
                    'role' => $role,
                    'depth' => $depth,
                    'parent' => $parent,
                    'unread' => (int) ($status['unseen'] ?? 0),
                    'total' => (int) ($status['messages'] ?? 0),
                ];
                if ($f->hasChildren()) {
                    $walk($f->children, $depth + 1, $f->path);
                }
            }
        };
        $walk($this->client->getFolders(true), 0, null);
        if ($owners) {
            $names = \App\Models\Vmail\Mailbox::query()->whereIn('username', array_keys($owners))->pluck('name', 'username');
            foreach ($out as &$row) {
                if (($row['role'] ?? '') === 'shared') {
                    // Владельца могли удалить, а общая папка осталась. Раньше обращение к
                    // отсутствующему ключу давало ошибку, и список папок отвечал 500 —
                    // веб-почта не открывалась совсем.
                    $row['ownerName'] = $names->get($row['owner']) ?: $row['owner'];
                }
            }
            unset($row);
        }

        $this->dedupeRoles($out);
        $this->markShared($out);

        // Системные — в фиксированном порядке, свои — по алфавиту после них.
        // Путь папки в IMAP — modified UTF-7 («&BBAEEQQX-»), сравнивать надо раскодированное имя и по правилам языка:
        // иначе «АНХК» встаёт раньше «Авиа» (заглавные байтами меньше строчных), а кириллица — вперемешку (обращение №29).
        $coll = class_exists('\Collator') ? new \Collator('ru_RU') : null;
        $keys = [];
        foreach ($out as $i => $row) {
            $keys[$i] = Mime::utf8Name($row['path']);
        }
        $idx = array_keys($out);
        usort($idx, function ($ia, $ib) use ($out, $keys, $coll) {
            $oa = self::ORDER[$out[$ia]['role']] ?? 10;
            $ob = self::ORDER[$out[$ib]['role']] ?? 10;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }

            return $coll ? $coll->compare($keys[$ia], $keys[$ib]) : strcmp(mb_strtolower($keys[$ia]), mb_strtolower($keys[$ib]));
        });
        $out = array_values(array_map(fn ($i) => $out[$i], $idx));

        return $this->folderCache = $out;
    }

    /**
     * Свои папки, открытые коллегам (Dovecot ACL): в строку папки добавляется shared_with = [{mail,name,level}].
     * GETACL по всем папкам — миллисекунды, но спрашиваем только если пользователь вообще кому-то открывал папки
     * (таблицу share_folder ведёт сам Dovecot через acl_shared_dict).
     */
    /**
     * Забыть кэш «есть ли у пользователя общие папки». Сразу после выдачи доступа признак
     * ещё минуту не появлялся, и казалось, что действие не сработало.
     */
    public static function forgetSharesCache(string $user): void
    {
        \Illuminate\Support\Facades\Cache::forget('shares-any.' . strtolower($user));
    }

    private function markShared(array &$out): void
    {
        $user = $this->user();
        try {
            $any = \Illuminate\Support\Facades\Cache::remember('shares-any.' . strtolower($user), 60, fn () => \Illuminate\Support\Facades\DB::connection('vmail')->table('share_folder')->where('from_user', $user)->exists());
        } catch (\Throwable) {
            return;
        }
        if (! $any) {
            return;
        }
        $conn = $this->client->getConnection();
        $found = [];
        foreach ($out as $i => $row) {
            if ($row['role'] === 'shared') {
                continue;
            }
            try {
                $lines = (array) $conn->requestAndResponse('GETACL', [$conn->escapeString($row['path'])])->data();
            } catch (\Throwable) {
                continue;
            }
            $with = [];
            foreach ($lines as $line) {
                // * ACL <папка> <кому> <права> <кому> <права> …
                $tok = is_array($line) ? $line : preg_split('/\s+/', trim((string) $line));
                if (strtoupper((string) ($tok[0] ?? '')) !== 'ACL') {
                    continue;
                }
                for ($k = 2; $k + 1 < count($tok); $k += 2) {
                    $id = strtolower((string) $tok[$k]);
                    if ($id === $user || $id === '' || $id[0] === '-' || ! str_contains($id, '@')) {
                        continue;   // сам владелец, запреты, anyone/authenticated — не «открыта коллеге»
                    }
                    $r = (string) $tok[$k + 1];
                    $with[$id] = str_contains($r, 'a') ? 'owner' : (str_contains($r, 'i') ? 'editor' : 'reader');
                }
            }
            if ($with) {
                $found[$i] = $with;
            }
        }
        if (! $found) {
            return;
        }
        $mails = array_unique(array_merge(...array_map('array_keys', $found)));
        $names = \App\Models\Vmail\Mailbox::query()->whereIn('username', $mails)->pluck('name', 'username');
        foreach ($found as $i => $with) {
            $list = [];
            foreach ($with as $mail => $level) {
                $list[] = ['mail' => $mail, 'name' => $names->get($mail) ?: $mail, 'level' => $level];
            }
            $out[$i]['shared_with'] = $list;
        }
    }

    /** Роль папки по её имени (без обращения к серверу). */
    public static function roleOfPath(string $path): string
    {
        return self::ROLES[strtoupper($path)] ?? 'custom';
    }

    /** Роль папки по её пути в этом ящике: inbox, sent, trash, spam, shared, custom. */
    public function folderRole(string $path): string
    {
        foreach ($this->folders() as $f) {
            if ($f['path'] === $path) {
                return (string) ($f['role'] ?? 'custom');
            }
        }

        return self::roleOfPath($path);
    }

    public function folderTitle(string $path): string
    {
        foreach ($this->folders() as $f) {
            if ($f['path'] === $path) {
                return $f['name'];
            }
        }

        return $path;
    }

    /** Путь системной папки по роли; папки «Архив» и «Отложенные» создаются при первом обращении. */
    /**
     * Две папки на одну роль («Deleted Items» рядом с Trash, «Sent Items» рядом с Sent — их создаёт Outlook или
     * почтовая программа с настройками времён Kerio): роль остаётся одной папке (предпочтительно с именем,
     * которое Dovecot объявляет как SPECIAL-USE), остальные показываются как обычные папки под своим именем.
     * Иначе в списке две «Корзины», а удаление и «Спам» попадают в случайную из них.
     */
    private function dedupeRoles(array &$out): void
    {
        $canon = ['inbox' => 'INBOX', 'trash' => 'Trash', 'spam' => 'Junk', 'sent' => 'Sent', 'drafts' => 'Drafts', 'archive' => 'Archive', 'snoozed' => 'Snoozed', 'lists' => 'Newsletters'];
        $byRole = [];
        foreach ($out as $i => $row) {
            if (! in_array($row['role'], ['custom', 'shared'], true)) {
                $byRole[$row['role']][] = $i;
            }
        }
        foreach ($byRole as $role => $idx) {
            if (count($idx) < 2) {
                continue;
            }
            $keep = $idx[0];
            foreach ($idx as $i) {
                if (strcasecmp($out[$i]['path'], $canon[$role] ?? '') === 0) {
                    $keep = $i;
                    break;
                }
            }
            foreach ($idx as $i) {
                if ($i !== $keep) {
                    $out[$i]['role'] = 'custom';
                    $out[$i]['name'] = Mime::utf8Name((string) basename($out[$i]['path']));
                }
            }
        }
    }

    /** Владелец общей папки Shared/<owner>/… (null — папка своя). */
    public static function sharedOwner(string $path): ?string
    {
        if (! str_starts_with($path, self::SHARED_PREFIX)) {
            return null;
        }
        $parts = explode('/', $path);

        return isset($parts[1]) && $parts[1] !== '' ? strtolower($parts[1]) : null;
    }

    /**
     * Папка с ролью для контекста: работаем в чужом общем ящике (Shared/<owner>/…) — его «Спам», «Корзина»…,
     * в своих папках — свои. Если нужная папка владельца нам не открыта — 422 с понятным текстом.
     */
    public function rolePathFor(string $context, string $role): string
    {
        $owner = self::sharedOwner($context);
        if ($owner === null) {
            return $this->rolePath($role);
        }
        foreach ($this->folders() as $f) {
            if (($f['role'] ?? '') === 'shared' && ($f['owner'] ?? '') === $owner && ($f['srole'] ?? '') === $role) {
                return $f['path'];
            }
        }
        throw MailException::denied('Папка «' . (self::TITLES[$role] ?? $role) . '» ящика ' . $owner . ' вам не открыта — попросите владельца или администратора');
    }

    /**
     * Куда на самом деле переносить: из чужого общего ящика в свою системную папку («Спам», «Корзина», «Архив»,
     * «Рассылки», «Входящие») — в такую же папку владельца; письма общего ящика не должны утекать в личные папки.
     */
    public function moveTarget(string $from, string $target): string
    {
        if (self::sharedOwner($from) === null || self::sharedOwner($target) !== null) {
            return $target;
        }
        $role = collect($this->folders())->firstWhere('path', $target)['role'] ?? 'custom';

        return in_array($role, ['spam', 'trash', 'archive', 'lists', 'inbox'], true) ? $this->rolePathFor($from, $role) : $target;
    }

    public function rolePath(string $role): string
    {
        foreach ($this->folders() as $f) {
            if ($f['role'] === $role) {
                return $f['path'];
            }
        }

        $name = match ($role) {
            'archive' => 'Archive', 'snoozed' => 'Snoozed', 'drafts' => 'Drafts', 'sent' => 'Sent', 'spam' => 'Junk', 'trash' => 'Trash', 'lists' => 'Newsletters',
            default => throw new \InvalidArgumentException("Нет папки с ролью {$role}"),
        };
        $this->client->createFolder($name, false);
        $this->client->getConnection()->subscribeFolder($name);
        $this->folderCache = null;
        $this->statusCache = null;

        return $name;
    }

    /** Разделитель уровней папок у этого сервера: обычно «/», у Maildir++ — «.». */
    private function separator(): string
    {
        try {
            foreach ($this->client->getFolders(false) as $f) {
                $d = (string) ($f->delimiter ?? '');
                if ($d !== '') {
                    return $d;
                }
            }
        } catch (\Throwable) {
        }

        return '/';
    }

    public function createFolder(string $name, ?string $parent = null): string
    {
        // Раньше вместе со слэшем молча вырезались точки: «Счета 1.2» становились «Счета 1 2»,
        // а имя из одних точек давало «Пустое имя папки» при непустом поле.
        // Убираем только разделитель уровней — остальное имя сервер принимает как есть.
        $sep = $this->separator();
        $clean = trim(str_replace([$sep, '/', "\r", "\n", "\t"], ' ', $name));
        $clean = trim(preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean);
        if (trim($name) === '') {
            throw MailException::invalid('Введите название папки');
        }
        if ($clean === '') {
            throw MailException::invalid('В названии остались только символы, которые нельзя использовать в имени папки (например, «' . $sep . '»)');
        }
        if (mb_strlen($clean) > 80) {
            throw MailException::invalid('Название длиннее 80 символов — сократите');
        }
        $name = $clean;
        // Родитель приходит в виде IMAP-пути (UTF-7), имя — в UTF-8; собираем в UTF-8, кодирует библиотека.
        $parentName = $parent ? mb_convert_encoding($parent, 'UTF-8', 'UTF7-IMAP') : null;
        $path = $parentName ? $parentName . '/' . $name : $name;
        foreach ($this->folders() as $f) {
            if (strcasecmp($f['path'], $this->utf7($path)) === 0) {
                throw MailException::invalid('Папка с таким именем уже есть');
            }
        }
        try {
            $this->client->createFolder($path, false, false);
        } catch (\Throwable $e) {
            // Dovecot отвечает «NO [CANNOT] Mailbox can't be created», и это попадало
            // человеку как есть. Имя «Shared» занято под общие папки коллег.
            $why = $e->getMessage();
            if (str_contains($why, 'CANNOT') || str_contains($why, "can't be created")) {
                throw MailException::invalid('Такое имя занято почтовым сервером (например, «' . rtrim(self::SHARED_PREFIX, '/') . '» — это место для общих папок). Выберите другое.');
            }
            if (str_contains($why, 'ALREADYEXISTS') || str_contains($why, 'already exists')) {
                throw MailException::invalid('Папка с таким именем уже есть');
            }
            throw MailException::upstream('Сервер не создал папку: ' . mb_substr($why, 0, 160));
        }
        $this->client->getConnection()->subscribeFolder($this->utf7($path));
        $this->folderCache = null;
        $this->statusCache = null;

        return $this->utf7($path);
    }

    public function renameFolder(string $path, string $newName): string
    {
        $folder = $this->folder($path);
        $parts = explode($folder->delimiter, $folder->full_name);
        array_pop($parts);
        $parts[] = trim(str_replace(['/', '.'], ' ', $newName));
        $new = implode($folder->delimiter, $parts);
        // Folder::move() шлёт старое имя в UTF-8, сервер ждёт UTF-7 — переименовываем через протокол сами.
        $this->client->getConnection()->renameFolder($path, $this->utf7($new));
        $this->folderCache = null;
        $this->statusCache = null;

        return $this->utf7($new);
    }

    public function deleteFolder(string $path): void
    {
        $this->folder($path)->delete(false);
        $this->folderCache = null;
        $this->statusCache = null;
    }

    public function folder(string $path): Folder
    {
        // Пути папок в интерфейсе уже в UTF-7 (как отдаёт сервер) — библиотеке об этом надо сказать явно.
        $folder = $this->client->getFolderByPath($path, true, true);
        if (! $folder) {
            throw MailException::notFound('Папка не найдена');
        }

        return $folder;
    }

    /**
     * Сколько места занято в ящике. Разметка индикатора в панели папок есть с самого начала,
     * но данные в неё не передавал ни один контроллер — справка обещала то, чего не было.
     *
     * @return array{usedKb:int,limitKb:int,percent:int}|null
     */
    public function quota(): ?array
    {
        try {
            $conn = $this->client->getConnection();
            $r = $conn->requestAndResponse('GETQUOTAROOT', [$conn->escapeString('INBOX')]);
            $line = implode(' ', array_map(fn ($x) => is_array($x) ? implode(' ', array_map('strval', $x)) : (string) $x, (array) $r->getResponse()));
        } catch (\Throwable) {
            return null;
        }
        // * QUOTA "User quota" (STORAGE 3504940 5242880)
        if (! preg_match('/STORAGE\s+(\d+)\s+(\d+)/i', $line, $m)) {
            return null;
        }
        $used = (int) $m[1];
        $limit = (int) $m[2];
        if ($limit <= 0) {
            return null;   // предела нет — показывать нечего
        }

        return ['usedKb' => $used, 'limitKb' => $limit, 'percent' => min(100, (int) round($used / $limit * 100))];
    }

    /** Быстрый статус папки для опроса «есть ли новое»: без списка писем. @return array{messages:int,unseen:int,uidnext:int} */
    public function status(string $path): array
    {
        try {
            $st = array_change_key_case((array) $this->folder($path)->status(), CASE_LOWER);
        } catch (\Throwable) {
            $st = [];
        }

        return ['messages' => (int) ($st['messages'] ?? 0), 'unseen' => (int) ($st['unseen'] ?? 0), 'uidnext' => (int) ($st['uidnext'] ?? 0)];
    }

    /**
     * Счётчики писем по папке.
     *
     * Сначала смотрим в общий ответ, полученный одной командой на весь ящик
     * (см. statusAll). Если его нет — спрашиваем отдельно, как раньше.
     */
    private function safeStatus(Folder $folder): array
    {
        $all = $this->statusAll();
        $key = strtolower($folder->path);
        if (isset($all[$key])) {
            return $all[$key];
        }

        try {
            return $folder->status();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Счётчики сразу по всем папкам — одной командой.
     *
     * Раньше на каждую папку уходила своя команда STATUS: у ящика с десятью папками это
     * десять обращений туда и обратно, 52 мс из 199 мс на открытие страницы, и чем больше
     * у человека папок, тем хуже. Dovecot умеет отдать список папок вместе со счётчиками
     * за один раз — расширение LIST-STATUS.
     *
     * Если сервер его не объявил или ответ разобрать не вышло, возвращаем пустоту:
     * вызывающий спросит счётчики по-старому. Почта не должна зависеть от того,
     * угадали ли мы формат ответа.
     *
     * @return array<string,array{messages:int,unseen:int,uidnext:int}> ключ — путь папки строчными
     */
    private function statusAll(): array
    {
        if ($this->statusCache !== null) {
            return $this->statusCache;
        }
        $this->statusCache = [];
        try {
            $conn = $this->client->getConnection();
            if (! str_contains(strtoupper($this->capabilities()), 'LIST-STATUS')) {
                return $this->statusCache;
            }
            $r = $conn->requestAndResponse('LIST', ['""', '"*"', 'RETURN', '(STATUS (MESSAGES UNSEEN UIDNEXT))']);
            foreach ((array) $r->data() as $line) {
                // Строка приходит разобранной в массив: ['STATUS', '<путь>', ['MESSAGES', '5', ...]]
                $flat = [];
                array_walk_recursive($line, function ($v) use (&$flat) { $flat[] = (string) $v; });
                if (($flat[0] ?? '') !== 'STATUS' || count($flat) < 4) {
                    continue;
                }
                $path = $flat[1];
                $pairs = array_slice($flat, 2);
                $vals = [];
                for ($i = 0; $i + 1 < count($pairs); $i += 2) {
                    $vals[strtolower($pairs[$i])] = (int) $pairs[$i + 1];
                }
                if ($vals !== []) {
                    $this->statusCache[strtolower($path)] = $vals;
                }
            }
        } catch (\Throwable) {
            // сервер ответил не так — работаем по-старому, папка за папкой
            $this->statusCache = [];
        }

        return $this->statusCache;
    }

    /** Что умеет сервер: строка возможностей, спрошенная один раз за соединение. */
    private function capabilities(): string
    {
        if ($this->capsCache === null) {
            try {
                $r = $this->client->getConnection()->requestAndResponse('CAPABILITY');
                $flat = [];
                array_walk_recursive((array) $r->data(), function ($v) use (&$flat) { $flat[] = (string) $v; });
                $this->capsCache = implode(' ', $flat);
            } catch (\Throwable) {
                $this->capsCache = '';
            }
        }

        return $this->capsCache;
    }

    private function utf7(string $path): string
    {
        return mb_convert_encoding($path, 'UTF7-IMAP', 'UTF-8');
    }

    /** Папка по IMAP-пути; если нет — создаётся (для действий правил «в папку»). */
    public function ensureFolder(string $path): string
    {
        // Путь может прийти и в UTF-8 (из старых правил), и в UTF-7 (из списка папок) — приводим к IMAP-виду.
        $path = preg_match('/[^\x00-\x7F]/', $path) ? $this->utf7($path) : $path;
        foreach ($this->folders() as $f) {
            if (strcasecmp($f['path'], $path) === 0) {
                return $f['path'];
            }
        }
        $this->client->createFolder($path, false, true);
        $this->client->getConnection()->subscribeFolder($path);
        $this->folderCache = null;
        $this->statusCache = null;

        return $path;
    }
}
