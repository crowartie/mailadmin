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
        'JUNK' => 'spam', 'SPAM' => 'spam', 'TRASH' => 'trash', 'DELETED ITEMS' => 'trash', 'ARCHIVE' => 'archive', 'SNOOZED' => 'snoozed',
    ];

    private const TITLES = [
        'inbox' => 'Входящие', 'drafts' => 'Черновики', 'sent' => 'Отправленные', 'spam' => 'Спам',
        'trash' => 'Корзина', 'archive' => 'Архив', 'snoozed' => 'Отложенные',
    ];

    private const ORDER = ['inbox' => 0, 'snoozed' => 1, 'drafts' => 2, 'sent' => 3, 'archive' => 4, 'spam' => 5, 'trash' => 6, 'shared' => 20];

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
            'archive' => 'Archive', 'snoozed' => 'Snoozed', 'drafts' => 'Drafts', 'sent' => 'Sent', 'spam' => 'Junk', 'trash' => 'Trash',
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
            'size' => $message->getSize(),
            'labels' => $labels,
            'messageId' => trim((string) ($message->getMessageId()->first() ?? ''), '<>'),
            'preview' => $preview,
        ];
    }

    // ── Чтение ───────────────────────────────────────────────────────────

    public function message(string $path, int $uid, bool $markSeen = true): array
    {
        $message = $this->folder($path)->query()->getMessageByUid($uid);
        abort_unless($message, 404, 'Письмо не найдено');

        $data = $this->full($message, $path);

        if ($markSeen && ! $message->getFlags()->has('seen')) {
            $this->flag($path, [$uid], '\\Seen', true);
            $data['seen'] = true;
        }

        $data['thread'] = $this->thread($message, $path);

        return $data;
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
                'name' => Charset::header($a->getName()) ?: ('вложение-' . ($i + 1)),
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

        $found = [];
        $paths = array_unique([$path, $this->rolePath('sent'), $this->rolePath('inbox')]);
        foreach ($paths as $p) {
            try {
                $folder = $this->folder($p);
            } catch (\Throwable) {
                continue;
            }
            $queries = [];
            if ($id !== '') {
                $queries[] = $folder->query()->whereHeader('References', $id);
                $queries[] = $folder->query()->whereInReplyTo($id);
            }
            foreach (array_slice($ids, -6) as $ref) {
                $queries[] = $folder->query()->whereMessageId($ref);
                $queries[] = $folder->query()->whereHeader('References', $ref);
            }
            foreach ($queries as $q) {
                try {
                    foreach ($q->setFetchBody(true)->setFetchFlags(true)->limit(15)->get() as $m) {
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
        }

        usort($found, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return array_values($found);
    }

    public function attachment(string $path, int $uid, int $index): Attachment
    {
        $message = $this->folder($path)->query()->getMessageByUid($uid);
        abort_unless($message, 404);
        $list = $message->getAttachments()->values();
        abort_unless(isset($list[$index]), 404, 'Вложение не найдено');

        return $list[$index];
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
}
