<?php

namespace App\Services\Mail;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * Всё, что веб-почта делает с ящиком: папки, списки, чтение, флаги, перемещения.
 * Работает поверх одного IMAP-соединения (пользовательского или master для задач).
 */
class MailStore
{
    public const PAGE = 40;

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

    public function __construct(private readonly Client $client)
    {
    }

    public function client(): Client
    {
        return $this->client;
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

    /** UID писем начиная с заданного (для дочитывания новых). @return int[] */
    public function searchFrom(string $path, int $fromUid): array
    {
        $this->client->openFolder($path, true);
        $r = $this->client->getConnection()->search(['UID', $fromUid . ':*'], IMAP::ST_UID)->validatedData();

        return array_values(array_filter(array_map('intval', is_array($r) ? $r : []), fn ($u) => $u >= $fromUid));
    }

    /** Сырые заголовки писем по UID. @return array<int,string> */
    public function rawHeaders(string $path, array $uids): array
    {
        if (! $uids) {
            return [];
        }
        $this->client->openFolder($path, true);
        $raw = $this->client->getConnection()->headers(array_values($uids), 'RFC822', IMAP::ST_UID)->validatedData();
        $out = [];
        foreach ((array) $raw as $uid => $text) {
            $out[(int) $uid] = (string) $text;
        }

        return $out;
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
                            $out[] = ['path' => $f->path, 'name' => self::TITLES['inbox'], 'role' => 'shared', 'depth' => 0, 'parent' => null, 'owner' => $owner, 'inbox' => true, 'unread' => (int) ($status['unseen'] ?? 0), 'total' => (int) ($status['messages'] ?? 0)];
                        }
                    }
                    if (count($parts) >= 3 && ! (count($parts) === 3 && strtoupper($parts[2]) === 'INBOX')) {
                        $owner = strtolower($parts[1]);
                        $rel = array_slice($parts, 2);
                        $leaf = self::utf8Name(end($rel));
                        $role = count($rel) === 1 ? (self::ROLES[strtoupper($leaf)] ?? null) : null;
                        $status = $this->safeStatus($f);
                        $owners[$owner] = true;
                        $out[] = [
                            'path' => $f->path,
                            'name' => $role ? self::TITLES[$role] : $leaf,
                            'role' => 'shared',
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
                    $row['ownerName'] = $names[$row['owner']] ?: $row['owner'];
                }
            }
            unset($row);
        }

        // Системные — в фиксированном порядке, свои — по алфавиту после них.
        usort($out, function ($a, $b) {
            $oa = self::ORDER[$a['role']] ?? 10;
            $ob = self::ORDER[$b['role']] ?? 10;

            return $oa <=> $ob ?: strcasecmp($a['path'], $b['path']);
        });

        return $this->folderCache = $out;
    }

    /** Имя папки из IMAP (modified UTF-7) → UTF-8. */
    public static function utf8Name(string $name): string
    {
        $d = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');

        return $d !== false && $d !== '' ? $d : $name;
    }

    /** Роль папки по её имени (без обращения к серверу). */
    public static function roleOfPath(string $path): string
    {
        return self::ROLES[strtoupper($path)] ?? 'custom';
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

        return $name;
    }

    public function createFolder(string $name, ?string $parent = null): string
    {
        $name = trim(str_replace(['/', '.'], ' ', $name));
        abort_if($name === '', 422, 'Пустое имя папки');
        // Родитель приходит в виде IMAP-пути (UTF-7), имя — в UTF-8; собираем в UTF-8, кодирует библиотека.
        $parentName = $parent ? mb_convert_encoding($parent, 'UTF-8', 'UTF7-IMAP') : null;
        $path = $parentName ? $parentName . '/' . $name : $name;
        foreach ($this->folders() as $f) {
            abort_if(strcasecmp($f['path'], $this->utf7($path)) === 0, 422, 'Папка с таким именем уже есть');
        }
        try {
            $this->client->createFolder($path, false, false);
        } catch (\Throwable $e) {
            abort(422, 'Сервер не создал папку: ' . $e->getMessage());
        }
        $this->client->getConnection()->subscribeFolder($this->utf7($path));
        $this->folderCache = null;

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

        return $this->utf7($new);
    }

    public function deleteFolder(string $path): void
    {
        $this->folder($path)->delete(false);
        $this->folderCache = null;
    }

    public function folder(string $path): Folder
    {
        // Пути папок в интерфейсе уже в UTF-7 (как отдаёт сервер) — библиотеке об этом надо сказать явно.
        $folder = $this->client->getFolderByPath($path, true, true);
        abort_unless($folder, 404, 'Папка не найдена');

        return $folder;
    }

    // ── Списки ───────────────────────────────────────────────────────────

    /**
     * Страница списка. $filter: all|unread|flagged|attach. $query — строка поиска
     * с операторами (см. SearchQuery); при поиске страницы считаются по результату.
     *
     * @return array{messages:array,total:int,page:int,pages:int}
     */
    public function list(string $path, int $page = 1, string $filter = 'all', ?string $query = null): array
    {
        $folder = $this->folder($path);
        $page = max(1, $page);

        $q = $folder->query()->setFetchBody(false)->setFetchFlags(true)->setFetchOrder('desc');
        $q = $this->applyFilter($q, $filter);
        $searching = $query !== null && trim($query) !== '';
        if ($searching) {
            $q = (new SearchQuery($query))->apply($q);
        }
        if (! $searching && $filter === 'all') {
            $q->all();
        }

        $messages = [];
        if ($searching || $filter !== 'all') {
            // Поиск: результат небольшой, считаем сами.
            $all = $q->get()->sortByDesc(fn (Message $m) => $m->getUid());
            $total = $all->count();
            $slice = $all->slice(($page - 1) * self::PAGE, self::PAGE);
            $previews = $this->previews($slice->map(fn (Message $m) => $m->getUid())->values()->all());
            foreach ($slice as $m) {
                $messages[] = $this->summary($m, $previews[$m->getUid()] ?? null);
            }
        } else {
            $total = (int) ($folder->examine()['exists'] ?? 0);
            if ($total > 0) {
                // Библиотека выбирает нужные 40 UID, но отдаёт их в порядке сервера — сортируем сами.
                $pageMessages = $q->limit(self::PAGE, $page)->get()->sortByDesc(fn (Message $m) => $m->getUid());
                $previews = $this->previews($pageMessages->map(fn (Message $m) => $m->getUid())->values()->all());
                foreach ($pageMessages as $m) {
                    $messages[] = $this->summary($m, $previews[$m->getUid()] ?? null);
                }
            }
        }

        return [
            'messages' => $messages,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE)),
        ];
    }

    private function applyFilter(WhereQuery $q, string $filter): WhereQuery
    {
        if (str_starts_with($filter, 'label:')) {
            return $q->whereKeyword('Lbl_' . (int) substr($filter, 6));
        }

        return match ($filter) {
            'unread' => $q->unseen(),
            'flagged' => $q->flagged(),
            'attach' => $q->whereHeader('Content-Type', 'multipart/mixed'),
            default => $q,
        };
    }

    /**
     * Первые слова письма для строки списка: IMAP PREVIEW (RFC 8970), Dovecot считает их сам,
     * тело письма не скачивается. Если сервер расширение не поддерживает — превью просто нет.
     *
     * Разбор библиотеки хорош для литералов ({N}…), но строку в кавычках режет по первому пробелу,
     * а сырой ответ, наоборот, надёжен для кавычек и теряет байты литералов. Берём лучшее из двух.
     *
     * @param  int[]  $uids
     * @return array<int,string>
     */
    private function previews(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $conn = $this->client->getConnection();
        $set = implode(',', array_map('intval', $uids));
        $out = [];
        // Длинные PREVIEW (например, отчёты Logwatch) библиотека разбирает с предупреждениями «Uninitialized string offset».
        // Laravel превращает предупреждение в исключение, разбор обрывается на середине ответа, и следующая команда
        // читает недочитанный хвост («Empty response», 500 на всю страницу). Поэтому на время разбора предупреждения глушим.
        set_error_handler(fn () => true, E_WARNING | E_NOTICE | E_DEPRECATED);
        try {
            foreach ((array) $conn->fetch(['PREVIEW'], $uids)->data() as $uid => $text) {
                if (is_string($text) && ! str_starts_with($text, '"')) {
                    $out[(int) $uid] = $text;
                }
            }
            foreach ((array) $conn->requestAndResponse('UID FETCH', [$set, '(PREVIEW)'], true)->data() as $line) {
                if (preg_match('/^\d+ FETCH \(UID (\d+) PREVIEW "((?:[^"\\\\]|\\\\.)*)"\)/s', (string) $line, $m)) {
                    $out[(int) $m[1]] = stripcslashes($m[2]);
                }
            }
        } catch (\Throwable) {
            return [];
        } finally {
            restore_error_handler();
        }
        foreach ($out as $uid => $text) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) Charset::fix($text)) ?? '');
            if ($text === '') {
                unset($out[$uid]);
            } else {
                $out[$uid] = mb_substr($text, 0, 160);
            }
        }

        return $out;
    }

    /** Размер письма; если сервер не ответил — 0, а не 500 на весь список. */
    private function sizeOf(Message $message): int
    {
        try {
            return (int) $message->getSize();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string,mixed> */
    public function summary(Message $message, ?string $preview = null): array
    {
        $from = $message->getFrom()->first();
        $to = $message->getTo()->first();
        $date = $message->getDate()->first();
        $subject = trim((string) Charset::header((string) ($message->getSubject()->first() ?? '')));
        $flags = $message->getFlags();
        $contentType = strtolower((string) ($message->getHeader()?->get('content_type')?->first() ?? ''));

        $labels = [];
        foreach ($flags->toArray() as $flag) {
            if (preg_match('/^Lbl_(\d+)$/i', (string) $flag, $m)) {
                $labels[] = (int) $m[1];
            }
        }

        return [
            'uid' => $message->getUid(),
            'subject' => $subject !== '' ? $subject : '(без темы)',
            'from' => $from ? ['name' => Charset::header($from->personal) ?: $from->mail, 'mail' => $from->mail] : ['name' => '—', 'mail' => ''],
            'toName' => $to ? (Charset::header($to->personal) ?: $to->mail) : null,
            'date' => $date ? $date->toIso8601String() : null,
            'seen' => $flags->has('seen'),
            'flagged' => $flags->has('flagged'),
            'answered' => $flags->has('answered'),
            'hasAttachments' => str_contains($contentType, 'multipart/mixed'),
            'size' => $this->sizeOf($message),
            'labels' => $labels,
            'messageId' => trim((string) ($message->getMessageId()->first() ?? ''), '<>'),
            'preview' => $preview,
        ];
    }

    // ── Чтение ───────────────────────────────────────────────────────────

    public function message(string $path, int $uid, bool $markSeen = true): array
    {
        try {
            $message = $this->folder($path)->query()->getMessageByUid($uid);
        } catch (\Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException) {
            // webklex на несуществующий UID бросает «no headers found», а не возвращает null
            $message = null;
        }
        abort_unless($message, 404, 'Письмо не найдено');

        $data = $this->full($message, $path);

        if ($markSeen && ! $message->getFlags()->has('seen')) {
            $this->flag($path, [$uid], '\\Seen', true);
            $data['seen'] = true;
        }

        // Цепочку ответов отдаём отдельным запросом (threadOf): письмо открывается сразу, поиск по папкам идёт фоном.
        $data['thread'] = null;

        return $data;
    }

    /** Цепочка ответов для уже открытого письма. */
    public function threadOf(string $path, int $uid): array
    {
        try {
            // Для цепочки нужны только заголовки письма — тело не тянем.
            $message = $this->folder($path)->query()->setFetchBody(false)->setFetchFlags(false)->getMessageByUid($uid);
        } catch (\Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException) {
            $message = null;
        }
        abort_unless($message, 404, 'Письмо не найдено');

        return $this->thread($message, $path);
    }

    /** Полное письмо: тело, адреса, вложения. */
    public function full(Message $message, string $path): array
    {
        $html = Charset::fix($message->hasHTMLBody() ? $message->getHTMLBody() : null);
        $text = Charset::fix($message->hasTextBody() ? $message->getTextBody() : null);

        $attachments = [];
        $inline = [];
        foreach ($message->getAttachments() as $i => $a) {
            /** @var Attachment $a */
            $cid = trim((string) ($a->id ?? ''), '<>');
            $isInline = $cid !== '' && $html && str_contains($html, 'cid:' . $cid);
            if ($isInline && $a->getSize() < 2_000_000) {
                $inline['cid:' . $cid] = 'data:' . $a->getMimeType() . ';base64,' . base64_encode($a->getContent());
            }
            $attachments[] = [
                'index' => $i,
                'name' => self::attachmentName($a, 'вложение-' . ($i + 1)),
                // getSize() — размер в base64 из структуры письма; получателю нужен размер самого файла.
                'size' => strlen((string) $a->getContent()) ?: $a->getSize(),
                'type' => $a->getMimeType(),
                'inline' => $isInline,
            ];
        }
        if ($html && $inline) {
            $html = strtr($html, $inline);
        }

        $refs = trim((string) ($message->getReferences()->first() ?? ''));

        return $this->summary($message) + [
            'folder' => $path,
            'html' => $html ? $this->sanitize($html) : null,
            'text' => $text,
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
            'replyTo' => $this->addresses($message->getReplyTo()),
            'inReplyTo' => trim((string) ($message->getInReplyTo()->first() ?? ''), '<>'),
            'references' => $refs,
            'attachments' => $attachments,
            'listUnsubscribe' => (string) ($message->getHeader()?->get('list_unsubscribe')?->first() ?? ''),
        ];
    }

    /**
     * Цепочка: письма той же переписки в этой папке и в «Отправленных» — по Message-ID,
     * In-Reply-To и References. Без базы индексов, поэтому только по заголовкам.
     */
    private function thread(Message $message, string $path): array
    {
        $id = trim((string) ($message->getMessageId()->first() ?? ''), '<>');
        $refs = preg_split('/\s+/', trim((string) ($message->getReferences()->first() ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $refs = array_map(fn ($r) => trim($r, '<>'), $refs);
        $inReplyTo = trim((string) ($message->getInReplyTo()->first() ?? ''), '<>');
        if ($inReplyTo) {
            $refs[] = $inReplyTo;
        }
        $ids = array_values(array_unique(array_filter($refs)));
        if ($id === '' && ! $ids) {
            return [];
        }

        // Сначала индекс цепочек в базе: один запрос по ключу вместо поиска по папкам.
        $members = ThreadIndex::threadOf($this->user(), $path, (int) $message->getUid());
        if ($members !== null) {
            $found = [];
            $byFolder = [];
            foreach ($members as $m) {
                $byFolder[$m['folder']][] = $m['uid'];
            }
            foreach ($byFolder as $p => $uids) {
                $got = [];
                try {
                    foreach ($this->folder($p)->query()->whereUidIn($uids)->setFetchBody(true)->setFetchFlags(true)->get() as $m) {
                        $got[] = (int) $m->getUid();
                        $found[] = $this->full($m, $p) + ['thread' => []];
                    }
                } catch (\Throwable) {
                    continue;
                }
                if ($missing = array_diff($uids, $got)) {
                    ThreadIndex::forget($this->user(), $p, $missing); // письмо удалили или переложили — индекс подчистим
                }
            }
            usort($found, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

            return $found;
        }

        // Папка ещё не проиндексирована — все условия в ОДИН IMAP SEARCH с OR на папку (раньше было до 14 отдельных поисков на папку,
        // на ящике в десятки тысяч писем каждый — проход по всей папке).
        $terms = [];
        if ($id !== '') {
            $terms[] = ['References', $id];
            $terms[] = ['In-Reply-To', $id];
        }
        foreach (array_slice($ids, -6) as $ref) {
            $terms[] = ['Message-ID', $ref];
            $terms[] = ['References', $ref];
        }
        $criteria = self::orCriteria($terms);

        $found = [];
        $paths = array_unique([$path, $this->rolePath('sent'), $this->rolePath('inbox')]);
        foreach ($paths as $p) {
            try {
                $folder = $this->folder($p);
                $this->client->openFolder($p, true);
                $uids = (array) $this->client->getConnection()->search($criteria)->validatedData();
                $uids = array_values(array_filter(array_map('intval', $uids), fn ($u) => $u > 0 && ! ($p === $path && $u === (int) $message->getUid())));
                if ($uids === []) {
                    continue;
                }
                $uids = array_slice($uids, -20);
                foreach ($folder->query()->whereUidIn($uids)->setFetchBody(true)->setFetchFlags(true)->get() as $m) {
                    $mid = trim((string) ($m->getMessageId()->first() ?? ''), '<>');
                    $key = $mid !== '' ? $mid : $p . '#' . $m->getUid();
                    if ($mid === $id || isset($found[$key])) {
                        continue;
                    }
                    $found[$key] = $this->full($m, $p) + ['thread' => []];
                }
            } catch (\Throwable) {
                // папка без нужных заголовков или сервер не поддерживает — пропускаем
            }
        }

        usort($found, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return array_values($found);
    }

    /**
     * Критерии IMAP SEARCH «любое из»: OR в IMAP бинарный, поэтому N условий = N-1 вложенных OR.
     *
     * @param  array<int,array{0:string,1:string}>  $terms  [заголовок, значение]
     * @return string[]
     */
    private static function orCriteria(array $terms): array
    {
        $quote = fn (string $v) => '"' . addcslashes($v, '"\\') . '"';
        $parts = array_map(fn ($t) => ['HEADER', $t[0], $quote($t[1])], $terms);
        $out = array_pop($parts) ?? [];
        while ($parts) {
            $out = array_merge(['OR'], array_pop($parts), $out);
        }

        return $out;
    }

    public function attachment(string $path, int $uid, int $index): Attachment
    {
        $message = $this->folder($path)->query()->getMessageByUid($uid);
        abort_unless($message, 404);
        $list = $message->getAttachments()->values();
        abort_unless(isset($list[$index]), 404, 'Вложение не найдено');

        return $list[$index];
    }

    /** Имя вложения: сначала из сырых заголовков части (библиотека ломается на koi8-r в две строки и RFC 2231), потом её версия. */
    public static function attachmentName(Attachment $a, string $fallback = 'attachment'): string
    {
        $raw = '';
        try {
            $part = (fn () => $this->part)->call($a);
            $raw = (string) ($part->getHeader()->raw ?? '');
        } catch (\Throwable) {
        }

        return ($raw !== '' ? Charset::attachmentName($raw) : null) ?: Charset::header($a->getName()) ?: $fallback;
    }

    public function raw(string $path, int $uid): string
    {
        $message = $this->folder($path)->query()->getMessageByUid($uid);
        abort_unless($message, 404);

        return (string) $message->getHeader()?->raw . "\r\n\r\n" . $message->getRawBody();
    }

    // ── Действия ─────────────────────────────────────────────────────────

    /** Установить или снять флаг у набора писем (\Seen, \Flagged, \Answered, Lbl_N …). */
    public function flag(string $path, array $uids, string $flag, bool $on): void
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        if (! $uids) {
            return;
        }
        $this->client->openFolder($path, true);
        $conn = $this->client->getConnection();
        foreach (array_chunk($uids, 200) as $chunk) {
            // Библиотечный store() умеет только диапазоны, а нам нужен произвольный набор UID.
            $conn->requestAndResponse('UID STORE', [implode(',', $chunk), ($on ? '+' : '-') . 'FLAGS.SILENT', $conn->escapeList([$flag])]);
        }
    }

    public function move(string $path, array $uids, string $target): void
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        if (! $uids || $target === $path) {
            return;
        }
        $this->client->openFolder($path, true);
        $this->client->getConnection()->moveManyMessages($uids, $target, IMAP::ST_UID);
        $this->folderCache = null;
        ThreadIndex::touch($this, [$path, $target]);
    }

    /** Удалить: из корзины — навсегда, откуда угодно ещё — в корзину. */
    public function delete(string $path, array $uids): void
    {
        $trash = $this->rolePath('trash');
        if ($path === $trash) {
            $this->flag($path, $uids, '\\Deleted', true);
            $this->client->openFolder($path, true);
            $this->client->getConnection()->expunge();

            return;
        }
        $this->move($path, $uids, $trash);
    }

    public function emptyFolder(string $path): void
    {
        $this->client->openFolder($path, true);
        $conn = $this->client->getConnection();
        $conn->requestAndResponse('STORE', ['1:*', '+FLAGS.SILENT', $conn->escapeList(['\\Deleted'])]);
        $conn->expunge();
        $this->folderCache = null;
    }

    /** UID письма по Message-ID в папке (для отложенных и напоминаний). */
    public function findByMessageId(string $path, string $messageId): ?int
    {
        try {
            $m = $this->folder($path)->query()->setFetchBody(false)->whereMessageId($messageId)->limit(1)->get()->first();

            return $m?->getUid();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Есть ли в папке ответ на письмо с таким Message-ID. */
    public function hasReplyTo(string $path, string $messageId): bool
    {
        try {
            $folder = $this->folder($path);
            if ($folder->query()->setFetchBody(false)->whereInReplyTo($messageId)->limit(1)->get()->count()) {
                return true;
            }

            return $folder->query()->setFetchBody(false)->whereHeader('References', $messageId)->limit(1)->get()->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Положить готовое письмо в папку; вернуть его UID (ищем по Message-ID). */
    public function append(string $path, string $raw, array $flags = ['\\Seen'], ?string $messageId = null): ?int
    {
        $this->folder($path)->appendMessage($raw, $flags, Carbon::now());
        ThreadIndex::touch($this, [$path]);
        if ($messageId) {
            return $this->findByMessageId($path, $messageId);
        }

        return null;
    }

    // ── Служебное ────────────────────────────────────────────────────────

    private function addresses($attribute): array
    {
        $out = [];
        // Attribute — только ArrayAccess, не итератор: перебираем через toArray().
        foreach (($attribute ? $attribute->toArray() : []) as $a) {
            $out[] = ['name' => Charset::header($a->personal) ?: $a->mail, 'mail' => $a->mail];
        }

        return $out;
    }

    /**
     * Письмо — чужой HTML. Режем скрипты, формы, внешние ресурсы и стили,
     * которые могут вылезти за пределы окна чтения. Картинки data: (встроенные) оставляем.
     */
    private function sanitize(string $html): string
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', storage_path('app/purifier'));
        $config->set('HTML.ForbiddenElements', ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'style', 'link', 'meta']);
        $config->set('HTML.ForbiddenAttributes', ['*@onclick', '*@onload', '*@onerror']);
        $config->set('CSS.AllowedProperties', ['color', 'background-color', 'font-weight', 'font-style', 'text-decoration', 'text-align', 'font-size', 'font-family', 'padding', 'margin', 'border', 'width', 'max-width', 'line-height', 'vertical-align']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true, 'tel' => true]);
        $config->set('URI.DisableExternalResources', true);   // внешние картинки — только по кнопке, позже
        $config->set('HTML.TargetBlank', true);
        $config->set('AutoFormat.RemoveEmpty', true);

        @mkdir(storage_path('app/purifier'), 0775, true);

        return (new \HTMLPurifier($config))->purify($html);
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

    private function safeStatus(Folder $folder): array
    {
        try {
            return $folder->status();
        } catch (\Throwable) {
            return [];
        }
    }

    private function utf7(string $path): string
    {
        return mb_convert_encoding($path, 'UTF7-IMAP', 'UTF-8');
    }

    // ── Поиск по IMAP для правил и решений по отправителям ─────────────
    /** Папка по IMAP-пути; если нет — создаётся (для действий правил «в папку»). */
    public function ensureFolder(string $path): string
    {
        // Путь может прийти и в UTF-8 (из старых правил), и в UTF-7 (из списка папок) — приводим к IMAP-виду.
        $path = preg_match('/[^ -]/', $path) ? $this->utf7($path) : $path;
        foreach ($this->folders() as $f) {
            if (strcasecmp($f['path'], $path) === 0) {
                return $f['path'];
            }
        }
        $this->client->createFolder($path, false, true);
        $this->client->getConnection()->subscribeFolder($path);
        $this->folderCache = null;

        return $path;
    }

    public function copy(string $path, array $uids, string $target): void
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        if (! $uids || $target === $path) {
            return;
        }
        $this->client->openFolder($path, true);
        $this->client->getConnection()->copyManyMessages($uids, $target, IMAP::ST_UID);
        ThreadIndex::touch($this, [$target]);
    }

    /** @return int[] все UID папки */
    public function searchAll(string $path): array
    {
        $this->client->openFolder($path, true);
        $r = $this->client->getConnection()->search(['ALL'], IMAP::ST_UID)->validatedData();

        return array_values(array_map('intval', is_array($r) ? $r : []));
    }

    /** UID писем отправителя: точный адрес или домен (без поддоменов). @return int[] */
    public function searchSender(string $path, string $match, string $value): array
    {
        $value = strtolower($value);
        $needle = $match === 'domain' ? '@' . $value : $value;
        $out = [];
        foreach ($this->headerIndex($path) as $uid => $h) {
            foreach ($h['from'] as $a) {
                if ($match === 'domain' ? str_ends_with($a, $needle) : $a === $needle) {
                    $out[] = $uid;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Условие правила {field, op, value, header?} → UID. Заголовки читаются целиком и сравниваются здесь:
     * IMAP SEARCH через полнотекстовый индекс режет длинные адреса и пропускает письма.
     *
     * @return int[]
     */
    public function searchCondition(string $path, array $c): array
    {
        $field = $c['field'] ?? 'subject';
        $op = $c['op'] ?? 'contains';
        $value = mb_strtolower(trim((string) ($c['value'] ?? '')));
        if ($value === '') {
            return [];
        }
        $header = strtolower((string) ($c['header'] ?? 'subject'));
        $out = [];
        foreach ($this->headerIndex($path) as $uid => $h) {
            $cands = match ($field) {
                'from' => $h['from'],
                'to' => $h['to'],
                'recipient' => array_merge($h['to'], $h['cc']),
                'header' => $h['raw'][$header] ?? [],
                default => [$h['subject']],
            };
            $hit = false;
            foreach ($cands as $cand) {
                $hit = match ($op) {
                    'is' => $cand === $value,
                    'starts' => str_starts_with($cand, $value),
                    'ends' => str_ends_with($cand, $value),
                    default => str_contains($cand, $value),
                };
                if ($hit) {
                    break;
                }
            }
            if ($op === 'not_contains' ? ! $hit : $hit) {
                $out[] = $uid;
            }
        }

        return $out;
    }

    /** @var array<string,array<int,array{from:string[],to:string[],cc:string[],subject:string,raw:array<string,string[]>}>> */
    private array $headerIndex = [];

    /**
     * Заголовки всех писем папки (адреса в нижнем регистре, тема раскодирована). Читается один раз за запрос,
     * порциями по 500 писем — для папки в десятки тысяч писем это секунды.
     */
    private function headerIndex(string $path): array
    {
        if (isset($this->headerIndex[$path])) {
            return $this->headerIndex[$path];
        }
        $uids = $this->searchAll($path);
        $conn = $this->client->getConnection();
        $index = [];
        foreach (array_chunk($uids, 500) as $chunk) {
            $raw = $conn->headers($chunk, 'RFC822', IMAP::ST_UID)->validatedData();
            foreach ($chunk as $uid) {
                $text = (string) ($raw[$uid] ?? '');
                if ($text === '') {
                    continue;
                }
                $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $text);
                $fields = [];
                if (preg_match_all('/^([A-Za-z0-9-]+):[ \t]*(.*)$/m', $unfolded, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $m) {
                        $fields[strtolower($m[1])][] = mb_strtolower((string) Charset::header(trim($m[2])));
                    }
                }
                $addr = function (string $name) use ($fields): array {
                    $out = [];
                    foreach ($fields[$name] ?? [] as $line) {
                        preg_match_all('/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+/i', $line, $aa);
                        foreach ($aa[0] as $a) {
                            $out[] = strtolower($a);
                        }
                    }

                    return $out;
                };
                $index[(int) $uid] = [
                    'from' => $addr('from'), 'to' => $addr('to'), 'cc' => $addr('cc'),
                    'subject' => $fields['subject'][0] ?? '', 'raw' => $fields,
                ];
            }
        }

        return $this->headerIndex[$path] = $index;
    }
}
