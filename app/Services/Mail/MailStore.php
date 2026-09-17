<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * Всё, что веб-почта делает с ящиком: списки, чтение, флаги, перемещения.
 * Работает поверх одного IMAP-соединения (пользовательского или master для задач).
 *
 * Дерево папок вынесено в FolderTree; методы про папки остались здесь обёртками,
 * чтобы вызывающий код не знал о разделении.
 */
class MailStore
{
    public const PAGE = 40;

    /** Сколько всего ждём поиск по всем папкам, секунд (срок проверяется между папками). */
    private const SEARCH_BUDGET = 12;

    /**
     * Сколько ждём ответа на поиск в одной папке, секунд. Поиск по готовому индексу
     * укладывается в миллисекунды, так что трёх секунд хватает с запасом; библиотека
     * успевает сделать по молчащему сокету несколько чтений подряд, поэтому папка,
     * занятая индексацией, обходится примерно втрое дороже этого срока.
     */
    private const SEARCH_TIMEOUT = 3;

    /** @see FolderTree::SHARED_PREFIX */
    public const SHARED_PREFIX = FolderTree::SHARED_PREFIX;

    private readonly FolderTree $tree;

    public function __construct(private readonly Client $client)
    {
        $this->tree = new FolderTree($client);
    }

    public function client(): Client
    {
        return $this->client;
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

    // ── Делегаты для вызывающих снаружи ───────────────────────────────────
    // Разбор писем живёт в Mime, чистка HTML — в MailHtml. Эти три метода зовут
    // контроллеры и службы, поэтому оставляем их здесь тонкими обёртками:
    // так разделение не потребовало трогать вызывающий код.

    /** @see Mime::attachmentName() */
    public static function attachmentName(Attachment $a, string $fallback): string
    {
        return Mime::attachmentName($a, $fallback);
    }

    /** @see Mime::messageIds() */
    public static function messageIds(mixed $raw): array
    {
        return Mime::messageIds($raw);
    }

    /** @see Mime::address() */
    public static function address(?string $name, ?string $mail): array
    {
        return Mime::address($name, $mail);
    }

    // ── Папки ────────────────────────────────────────────────────────────
    // Дерево папок живёт в FolderTree. Здесь остаются вызовы той же формы,
    // что и раньше: так разделение не потребовало трогать контроллеры и службы.

    /** @see FolderTree::folders() */
    public function folders(): array
    {
        return $this->tree->folders();
    }

    /** @see FolderTree::folder() */
    public function folder(string $path): Folder
    {
        return $this->tree->folder($path);
    }

    /** @see FolderTree::folderRole() */
    public function folderRole(string $path): string
    {
        return $this->tree->folderRole($path);
    }

    /** @see FolderTree::folderTitle() */
    public function folderTitle(string $path): string
    {
        return $this->tree->folderTitle($path);
    }

    /** @see FolderTree::folderStatus() */
    public function folderStatus(string $path): array
    {
        return $this->tree->folderStatus($path);
    }

    /** @see FolderTree::status() */
    public function status(string $path): array
    {
        return $this->tree->status($path);
    }

    /** @see FolderTree::quota() */
    public function quota(): ?array
    {
        return $this->tree->quota();
    }

    /** @see FolderTree::rolePath() */
    public function rolePath(string $role): string
    {
        return $this->tree->rolePath($role);
    }

    /** @see FolderTree::rolePathFor() */
    public function rolePathFor(string $context, string $role): string
    {
        return $this->tree->rolePathFor($context, $role);
    }

    /** @see FolderTree::moveTarget() */
    public function moveTarget(string $from, string $target): string
    {
        return $this->tree->moveTarget($from, $target);
    }

    /** @see FolderTree::createFolder() */
    public function createFolder(string $name, ?string $parent = null): string
    {
        return $this->tree->createFolder($name, $parent);
    }

    /** @see FolderTree::renameFolder() */
    public function renameFolder(string $path, string $newName): string
    {
        return $this->tree->renameFolder($path, $newName);
    }

    /** @see FolderTree::deleteFolder() */
    public function deleteFolder(string $path): void
    {
        $this->tree->deleteFolder($path);
    }

    /** @see FolderTree::ensureFolder() */
    public function ensureFolder(string $path): string
    {
        return $this->tree->ensureFolder($path);
    }

    /** @see FolderTree::user() */
    public function user(): string
    {
        return $this->tree->user();
    }

    /** @see FolderTree::roleOfPath() */
    public static function roleOfPath(string $path): string
    {
        return FolderTree::roleOfPath($path);
    }

    /** @see FolderTree::sharedOwner() */
    public static function sharedOwner(string $path): ?string
    {
        return FolderTree::sharedOwner($path);
    }

    /** @see FolderTree::forgetSharesCache() */
    public static function forgetSharesCache(string $user): void
    {
        FolderTree::forgetSharesCache($user);
    }

    // ── Списки ───────────────────────────────────────────────────────────

    /**
     * Страница списка. $filter: all|unread|flagged|attach. $query — строка поиска
     * с операторами (см. SearchQuery); при поиске страницы считаются по результату.
     *
     * @return array{messages:array,total:int,page:int,pages:int}
     */
    /**
     * Поиск по всем своим папкам. Справка обещает «искать по всем папкам», а поиск работал
     * только по текущей: письмо, разложенное правилом, найти было нельзя.
     * Корзину, спам и чужие папки не трогаем — если человек ищет там, он открывает их сам.
     *
     * @return array{messages:array,total:int,page:int,pages:int}
     */
    public function searchEverywhere(string $query, int $page = 1, string $sort = 'date'): array
    {
        $page = max(1, $page);
        $paths = [];
        foreach ($this->folders() as $f) {
            if (! in_array($f['role'] ?? '', ['spam', 'trash', 'shared'], true)) {
                $paths[] = $f['path'];
            }
        }
        // Ограничение на число папок: иначе на большом дереве это десятки поисков подряд.
        $paths = array_slice(array_values(array_unique($paths)), 0, 15);

        $hits = [];
        $skipped = [];
        // Папка, которую сервер в этот момент индексирует, отвечает не сразу, а по таймауту.
        // Пятнадцать таких папок складывались в шесть минут — страница отваливалась раньше,
        // чем приходил ответ. Поэтому на время перебора ждём каждый ответ недолго и держим
        // общий срок: что успели — показываем, остальные папки честно называем.
        $deadline = microtime(true) + self::SEARCH_BUDGET;
        $this->withTimeout(self::SEARCH_TIMEOUT);
        try {
            foreach ($paths as $i => $p) {
                if (microtime(true) > $deadline) {
                    foreach (array_slice($paths, $i) as $rest) {
                        $skipped[] = $this->folderTitle($rest);
                    }
                    break;
                }
                try {
                    $q = $this->folder($p)->query()->setFetchBody(false)->setFetchFlags(true);
                    (new SearchQuery($query))->apply($q);
                    $uids = $this->searchUids($q, $p);
                } catch (\Throwable) {
                    // Не роняем весь поиск, но и не делаем вид, что здесь ничего не нашлось.
                    $skipped[] = $this->folderTitle($p);
                    continue;
                }
                rsort($uids);
                foreach (array_slice($uids, 0, 200) as $uid) {
                    $hits[] = [$p, $uid];
                }
            }
        } finally {
            $this->withTimeout(null);
        }
        $total = count($hits);
        $slice = array_slice($hits, ($page - 1) * self::PAGE, self::PAGE);

        $messages = [];
        $byFolder = [];
        foreach ($slice as [$p, $uid]) {
            $byFolder[$p][] = $uid;
        }
        foreach ($byFolder as $p => $uids) {
            try {
                $this->client->openFolder($p, true);
                $previews = $this->previews($uids);
                foreach ($this->folder($p)->query()->whereUidIn($uids)->setFetchBody(false)->setFetchFlags(true)->get() as $m) {
                    $row = $this->summary($m, $previews[(int) $m->getUid()] ?? null);
                    // Строка знает свою папку: иначе щелчок открывал бы письмо из текущей.
                    $row['folder'] = $p;
                    $row['folderName'] = Mime::utf8Name(basename(str_replace('.', '/', $p))) ?: $p;
                    $messages[] = $row;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        usort($messages, fn ($a, $b) => strtotime((string) $b['date']) <=> strtotime((string) $a['date']));

        return [
            'messages' => $messages,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE)),
            'everywhere' => true,
            'skipped' => $skipped,
        ];
    }

    public function list(string $path, int $page = 1, string $filter = 'all', ?string $query = null, string $sort = 'date'): array
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
        // Порядок писем задаёт дата письма, а не внутренний номер: письмо, перенесённое в папку
        // сегодня, получает самый большой номер и без сортировки встаёт наверх, даже если ему два года.
        $sorted = $this->sortedUids($searching || $filter !== 'all' ? $q : null, $path, $sort);
        if ($sorted !== null) {
            $total = count($sorted);
            $slice = array_slice($sorted, ($page - 1) * self::PAGE, self::PAGE);
            $messages = $slice ? ($this->pageFastUids($slice) ?? []) : [];
            // FETCH отдаёт письма в своём порядке, а не в том, в каком мы запросили UID:
            // раскладываем строки обратно по порядку сортировки.
            if ($messages) {
                $byUid = [];
                foreach ($messages as $row) {
                    $byUid[(int) ($row['uid'] ?? 0)] = $row;
                }
                $ordered = [];
                foreach ($slice as $uid) {
                    if (isset($byUid[(int) $uid])) {
                        $ordered[] = $byUid[(int) $uid];
                    }
                }
                if (count($ordered) === count($messages)) {
                    $messages = $ordered;
                }
            }
            if ($messages || ! $slice) {
                return [
                    'messages' => $messages,
                    'total' => $total,
                    'page' => $page,
                    'pages' => max(1, (int) ceil($total / self::PAGE)),
                ];
            }
        }
        if ($searching || $filter !== 'all') {
            // Поиск/фильтр: сервер отдаёт только UID, страницу берём одним FETCH — раньше библиотека
            // тянула и разбирала заголовки всех найденных писем (сотни непрочитанных — секунды).
            $uids = $this->searchUids($q, $path, $searching);
            rsort($uids);
            $total = count($uids);
            $slice = array_slice($uids, ($page - 1) * self::PAGE, self::PAGE);
            $messages = $slice ? ($this->pageFastUids($slice) ?? $this->pageViaLibraryUids($q, $slice)) : [];
        } else {
            $total = (int) ($folder->examine()['exists'] ?? 0);
            if ($total > 0) {
                $messages = $this->pageFast($page, $total) ?? $this->pageViaLibrary($q, $page);
            }
        }

        return [
            'messages' => $messages,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE)),
        ];
    }

    /**
     * UID писем папки в порядке от новых к старым по времени получения (IMAP SORT, RFC 5256).
     *
     * Именно ARRIVAL, а не DATE: заголовок «Дата» пишет отправитель, и спам с датой из будущего
     * вставал бы в начало списка (в общем ящике info такие письма есть — «24 сентября», «0200 год»).
     * Время получения подделать нельзя, а при переносе письма в другую папку оно сохраняется —
     * ради этого сортировка и понадобилась: раньше порядок задавал внутренний номер письма.
     * $q — построенный запрос с условиями поиска/фильтра; null — вся папка; $path — папка для кэша.
     *
     * Возвращает null, если сервер SORT не поддерживает или ответил не так, как ожидалось:
     * вызывающий код тогда работает по-старому, порядком по внутреннему номеру.
     *
     * @return array<int,int>|null
     */
    /** Ключи IMAP SORT (RFC 5256) для порядков, которые может выбрать человек. */
    private const SORT_KEYS = [
        'date' => '(REVERSE ARRIVAL)',
        'date-asc' => '(ARRIVAL)',
        'from' => '(FROM ARRIVAL)',
        'subject' => '(SUBJECT ARRIVAL)',
        'size' => '(REVERSE SIZE)',
    ];

    private function sortedUids(?WhereQuery $q, string $path, string $sort = 'date'): ?array
    {
        // Если на этой папке SORT уже оказывался медленным — не трогаем его снова.
        $key = self::SORT_KEYS[$sort] ?? self::SORT_KEYS['date'];
        // Порядок, отличный от обычного, человек выбрал сам — тогда ждём сервер, даже если он небыстрый.
        $slowKey = $sort === 'date' ? 'sort-slow.' . md5($this->user() . '|' . $path) : null;
        if ($slowKey && \Illuminate\Support\Facades\Cache::get($slowKey)) {
            return null;
        }
        $started = microtime(true);
        try {
            // SORT работает только по выбранной папке: без SELECT сервер отвечает отказом.
            $this->client->openFolder($path, true);
            $conn = $this->client->getConnection();
            $criteria = 'ALL';
            if ($q !== null) {
                $generated = trim((string) $q->generate_query());
                if ($generated === '') {
                    return null;
                }
                $criteria = $generated;
            }
            $r = $conn->requestAndResponse('UID SORT', [$key, 'UTF-8', $criteria]);
            $lines = (array) $r->data();
        } catch (\Throwable) {
            // Сервер не ответил на SORT — соединение осталось с недочитанным ответом,
            // и следующая команда получила бы его вместо своего. Поднимаем заново
            // и работаем по-старому, порядком по внутреннему номеру.
            $this->reconnect();

            return null;
        }

        // Ответ сервера: строка «SORT 119 118 117 …» и следом завершающая строка вида «OK Sort completed».
        // Берём только строку результата, остальные пропускаем.
        $uids = [];
        foreach ($lines as $line) {
            $parts = array_values(array_filter(
                is_array($line) ? $line : preg_split('/\s+/', (string) $line, -1, PREG_SPLIT_NO_EMPTY),
                'is_scalar'
            ));
            if (! $parts || (! is_numeric($parts[0]) && strcasecmp((string) $parts[0], 'SORT') !== 0)) {
                continue;
            }
            foreach ($parts as $p) {
                if (is_numeric($p)) {
                    $uids[] = (int) $p;
                }
            }
        }

        if ($slowKey && (microtime(true) - $started) > 2.0) {
            // Большая папка без кэша сортировки: один раз отдали правильный порядок,
            // дальше не тормозим список — вернёмся к этому через полчаса.
            \Illuminate\Support\Facades\Cache::put($slowKey, 1, now()->addMinutes(30));
        }

        return $uids ?: null;
    }

    /**
     * UID писем папки по условиям запроса.
     *
     * Папку выбираем явно: поиск идёт по выбранной папке, а порядок сортировки мог
     * не понадобиться, и тогда SELECT до этого места никто не делал.
     *
     * Неудачный поиск обязательно сопровождается переподключением. Когда сервер не
     * успевает ответить (обычно папка в этот момент индексируется), ответ приходит уже
     * после того, как библиотека перестала его ждать, и дальше по этому соединению
     * читаются чужие ответы: «поиск везде» после первой такой папки молча пустел,
     * а открытое письмо отвечало «BAD No mailbox selected».
     *
     * @return int[]
     */
    private function searchUids(WhereQuery $q, string $path, bool $searching = true): array
    {
        try {
            $this->client->openFolder($path, true);

            return array_map('intval', $q->search()->all());
        } catch (\Throwable $e) {
            $this->reconnect();
            // Библиотека заворачивает настоящую причину в своё «failed to fetch messages»,
            // поэтому смотрим всю цепочку: там и таймаут, и разбор ответа.
            $why = '';
            for ($x = $e; $x !== null; $x = $x->getPrevious()) {
                $why .= ' ' . mb_strtolower($x->getMessage());
            }
            // «empty response» — библиотека не дождалась ответа: для сервера это та же индексация.
            if (str_contains($why, 'timed out') || str_contains($why, 'timeout') || str_contains($why, 'indexing') || str_contains($why, 'empty response')) {
                throw MailException::busy($searching ? 'Поиск по этой папке ещё готовится (сервер достраивает индекс) — попробуйте через минуту' : 'Папка занята индексацией — попробуйте через минуту');
            }
            if (str_contains($why, 'bad') || str_contains($why, 'parse') || str_contains($why, 'syntax')) {
                throw MailException::invalid('Почтовый сервер не понял запрос. Уберите кавычки и спецсимволы или упростите его.');
            }
            throw MailException::upstream('Почтовый сервер не смог выполнить поиск: ' . mb_substr($e->getMessage(), 0, 160));
        }
    }

    /**
     * Ждать ответа сервера не дольше заданного (null — вернуть обычное ожидание).
     * Срок задаётся сокету при подключении, поэтому соединение переустанавливаем.
     */
    private function withTimeout(?int $seconds): void
    {
        $was = $this->timeout ??= $this->client->timeout;
        $now = $seconds ?? $was;
        if ($now === $this->client->timeout) {
            return;
        }
        $this->client->timeout = $now;
        $this->reconnect();
    }

    /** Обычное ожидание ответа сервера — запоминаем при первой смене. */
    private ?int $timeout = null;

    /** Поднять соединение заново после сбойной команды (см. searchUids). */
    private function reconnect(): void
    {
        try {
            $this->client->disconnect();
        } catch (\Throwable) {
        }
        try {
            $this->client->connect();
        } catch (\Throwable) {
        }
    }

    private function applyFilter(WhereQuery $q, string $filter): WhereQuery
    {
        if (str_starts_with($filter, 'label:')) {
            return $q->whereKeyword('Lbl_' . (int) substr($filter, 6));
        }

        return match ($filter) {
            'unread' => $q->unseen(),
            'flagged' => $q->where('FLAGGED'),   // ->flagged() в php-imap 6.2 требует аргумент и падает
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
    /**
     * Страница списка одним FETCH по номерам сообщений (последние N в папке — это и есть новые сверху):
     * без SEARCH по всей папке и без разбора полных заголовков библиотекой — в 6–8 раз быстрее на больших ящиках.
     * Возвращает null, если сервер ответил неожиданно — тогда список строится прежним путём.
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function pageFast(int $page, int $total): ?array
    {
        $hi = $total - ($page - 1) * self::PAGE;
        if ($hi < 1) {
            return [];
        }
        $lo = max(1, $hi - self::PAGE + 1);

        return $this->fetchPage($lo, $hi, IMAP::ST_MSGN, $hi - $lo + 1);
    }

    /** То же для заранее известных UID (результат поиска или фильтра). */
    private function pageFastUids(array $uids): ?array
    {
        return $this->fetchPage(array_values($uids), null, IMAP::ST_UID, count($uids));
    }

    /** @return array<int,array<string,mixed>>|null */
    private function fetchPage(int|array $from, ?int $to, int $mode, int $expected): ?array
    {
        $items = ['UID', 'FLAGS', 'RFC822.SIZE', 'INTERNALDATE', 'PREVIEW', 'BODY.PEEK[HEADER.FIELDS (FROM TO DATE SUBJECT MESSAGE-ID CONTENT-TYPE)]'];
        // Предупреждения разборщика на длинных PREVIEW не должны превращаться в исключения (см. previews()).
        set_error_handler(fn () => true, E_WARNING | E_NOTICE | E_DEPRECATED);
        try {
            $rows = (array) $this->client->getConnection()->fetch($items, $from, $to, $mode)->data();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('list: быстрый FETCH не удался, строю список библиотекой: ' . $e->getMessage());

            return null;
        } finally {
            restore_error_handler();
        }
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['UID'])) {
                continue;
            }
            $out[] = $this->summaryFromFetch($row);
        }
        if (count($out) !== $expected) {
            return null;
        }
        usort($out, fn ($a, $b) => $b['uid'] <=> $a['uid']);

        return $out;
    }

    /** Прежний путь для найденных UID — по одному, только если быстрый FETCH не сработал. */
    private function pageViaLibraryUids(WhereQuery $q, array $uids): array
    {
        $messages = [];
        $previews = $this->previews($uids);
        foreach ($uids as $uid) {
            try {
                $m = $q->getMessageByUid((int) $uid);
            } catch (\Throwable) {
                continue;
            }
            if ($m) {
                $messages[] = $this->summary($m, $previews[$uid] ?? null);
            }
        }

        return $messages;
    }

    /** Прежний путь: библиотека выбирает нужные UID и разбирает заголовки сама. */
    private function pageViaLibrary(WhereQuery $q, int $page): array
    {
        $messages = [];
        $pageMessages = $q->limit(self::PAGE, $page)->get()->sortByDesc(fn (Message $m) => $m->getUid());
        $previews = $this->previews($pageMessages->map(fn (Message $m) => $m->getUid())->values()->all());
        foreach ($pageMessages as $m) {
            $messages[] = $this->summary($m, $previews[$m->getUid()] ?? null);
        }

        return $messages;
    }

    /** Строка списка из сырого ответа FETCH — те же поля, что даёт summary(). */
    private function summaryFromFetch(array $row): array
    {
        // Библиотека режет «BODY[HEADER.FIELDS (FROM …)]» на ключ «BODY[HEADER.FIELDS» и список, где последний элемент — сам текст заголовков.
        $headers = '';
        foreach ($row as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'BODY[')) {
                $headers = is_array($value) ? (string) (end($value) ?: '') : (string) $value;
            }
        }
        $h = Mime::parseHeaderFields($headers);
        $flags = array_map('strtolower', array_map('strval', (array) ($row['FLAGS'] ?? [])));
        $labels = [];
        foreach ($flags as $flag) {
            if (preg_match('/^lbl_(\d+)$/', $flag, $m)) {
                $labels[] = (int) $m[1];
            }
        }
        $subject = trim((string) Charset::header($h['subject'] ?? ''));
        $from = Mime::firstAddress($h['from'] ?? '');
        $to = Mime::firstAddress($h['to'] ?? '');
        $date = null;
        foreach ([$h['date'] ?? null, $row['INTERNALDATE'] ?? null] as $raw) {
            if ($raw === null || trim((string) $raw) === '') {
                continue;
            }
            try {
                $date = \Carbon\Carbon::parse(preg_replace('/\s*\([^)]*\)\s*$/', '', trim((string) $raw)))->toIso8601String();
                break;
            } catch (\Throwable) {
                // кривой Date: — возьмём время получения (INTERNALDATE)
            }
        }
        $preview = null;
        if (isset($row['PREVIEW']) && is_string($row['PREVIEW'])) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) Charset::fix($row['PREVIEW'])) ?? '');
            $preview = $text !== '' ? mb_substr($text, 0, 160) : null;
        }

        return [
            'uid' => (int) $row['UID'],
            'subject' => $subject !== '' ? $subject : '(без темы)',
            'from' => $from ?? ['name' => '—', 'mail' => ''],
            'toName' => $to['name'] ?? null,
            'date' => $date,
            'seen' => in_array('\\seen', $flags, true),
            'flagged' => in_array('\\flagged', $flags, true),
            'answered' => in_array('\\answered', $flags, true),
            'hasAttachments' => str_contains(strtolower($h['content-type'] ?? ''), 'multipart/mixed'),
            'size' => (int) ($row['RFC822.SIZE'] ?? 0),
            'labels' => $labels,
            'messageId' => trim((string) ($h['message-id'] ?? ''), " \t<>"),
            'preview' => $preview,
        ];
    }

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
            'from' => $from ? Mime::address($from->personal, $from->mail) : ['name' => '—', 'mail' => ''],
            'toName' => $to ? Mime::address($to->personal, $to->mail)['name'] : null,
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
        // Сначала только заголовки: их хватает и для шапки письма, и для решения,
        // можно ли показать письмо, не скачивая вложения (см. fullLight).
        try {
            $message = $this->folder($path)->query()->setFetchBody(false)->setFetchFlags(true)->getMessageByUid($uid);
        } catch (\Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException) {
            // webklex на несуществующий UID бросает «no headers found», а не возвращает null
            $message = null;
        }
        if (! $message) {
            throw MailException::notFound('Письмо не найдено');
        }

        $data = $this->fullLight($message, $path, $uid);
        if ($data === null) {
            // Структура письма не разобралась (редкий случай) — работаем по-старому:
            // качаем письмо целиком и разбираем библиотекой.
            $heavy = $this->folder($path)->query()->getMessageByUid($uid);
            if (! $heavy) {
                throw MailException::notFound('Письмо не найдено');
            }
            $data = $this->full($heavy, $path);
        }

        if ($markSeen && ! $message->getFlags()->has('seen')) {
            $this->flag($path, [$uid], '\\Seen', true);
            $data['seen'] = true;
        }

        // Цепочку ответов отдаём отдельным запросом (threadOf): письмо открывается сразу, поиск по папкам идёт фоном.
        $data['thread'] = null;

        return $data;
    }

    /** Сколько писем в цепочке осталось за пределами показанного (см. threadOf). */
    public int $threadHidden = 0;

    /** Цепочка ответов для уже открытого письма. */
    public function threadOf(string $path, int $uid): array
    {
        try {
            // Для цепочки нужны только заголовки письма — тело не тянем.
            $message = $this->folder($path)->query()->setFetchBody(false)->setFetchFlags(false)->getMessageByUid($uid);
        } catch (\Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException) {
            $message = null;
        }
        if (! $message) {
            throw MailException::notFound('Письмо не найдено');
        }

        return $this->thread($message, $path);
    }

    /**
     * Письмо без скачивания вложений: тело берём отдельными частями, список вложений —
     * из структуры письма. Возвращает null, если сервер описал письмо не так, как мы
     * понимаем, — тогда вызывающий читает письмо целиком, как раньше.
     *
     * @return array<string,mixed>|null
     */
    private function fullLight(Message $message, string $path, int $uid): ?array
    {
        $parts = Structure::of($this->client, $path, $uid);
        if ($parts === null) {
            return null;
        }
        $bodies = Structure::bodyParts($parts);
        if (! $bodies) {
            return null;   // текста не нашлось — пусть библиотека попробует по-своему
        }
        $raw = Structure::fetchParts($this->client, $path, $uid, $bodies);
        $html = null;
        $text = null;
        foreach ($bodies as $b) {
            if (! isset($raw[$b['no']])) {
                return null;   // часть не пришла: показывать письмо без текста нельзя
            }
            // Завершающий перевод строки к письму не относится — библиотека его тоже убирает.
            $content = rtrim(Charset::body($raw[$b['no']], (string) $b['charset']), "\r\n");
            if ($b['subtype'] === 'html') {
                $html = $content;
            } else {
                $text = $content;
            }
        }

        // Вложения: имена, типы и размеры уже известны из структуры — качать нечего.
        $list = Structure::attachments($parts);
        $attachments = [];
        $heavyInline = [];
        $lightInline = [];
        foreach ($list as $i => $a) {
            $cid = (string) $a['id'];
            $isInline = $cid !== '' && $html !== null && str_contains($html, 'cid:' . $cid);
            // Картинку до двух мегабайт вшиваем в письмо строкой data:, тяжёлую — ссылкой.
            $heavy = $isInline && $a['size'] >= 2_000_000;
            if ($isInline && ! $heavy) {
                $lightInline[$cid] = $a;
            } elseif ($heavy) {
                $heavyInline['cid:' . $cid] = '/mail/api/message/' . rawurlencode($path) . '/' . $uid . '/attachment/' . $i . '?inline=1';
            }
            $attachments[] = [
                'index' => $i,
                'name' => $a['name'] !== '' ? $a['name'] : 'вложение-' . ($i + 1),
                'size' => $a['size'],
                'type' => $a['mime'],
                'inline' => $isInline && ! $heavy,
            ];
        }
        if ($lightInline) {
            // Встроенные картинки — единственное, что дочитываем помимо текста.
            $got = Structure::fetchParts($this->client, $path, $uid, array_values($lightInline));
            foreach ($lightInline as $cid => $a) {
                if (isset($got[$a['no']])) {
                    $heavyInline['cid:' . $cid] = 'data:' . $a['mime'] . ';base64,' . base64_encode($got[$a['no']]);
                }
            }
        }
        if ($html !== null && $heavyInline) {
            $html = strtr($html, $heavyInline);
        }

        $rawHeader = (string) ($message->getHeader()?->raw ?? '');
        $refIds = Mime::messageIds(Mime::headerValue($rawHeader, 'References') ?? $message->getReferences()->toArray());
        $refs = implode(' ', array_map(fn ($id) => '<' . $id . '>', $refIds));
        $inReplyTo = Mime::messageIds(Mime::headerValue($rawHeader, 'In-Reply-To') ?? $message->getInReplyTo()->toArray())[0] ?? '';

        return $this->summary($message) + [
            'folder' => $path,
            'html' => $html !== null && $html !== '' ? MailHtml::sanitize($html) : null,
            'text' => $text,
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
            'bcc' => $this->addresses($message->getBcc()),
            'replyTo' => $this->addresses($message->getReplyTo()),
            'inReplyTo' => $inReplyTo,
            'references' => $refs,
            'attachments' => $attachments,
            // Признак вложений в шапке считался по заголовку Content-Type, а теперь известен точно.
            'hasAttachments' => (bool) array_filter($attachments, fn ($a) => empty($a['inline'])),
            'listUnsubscribe' => (string) ($message->getHeader()?->get('list_unsubscribe')?->first() ?? ''),
        ];
    }

    /** Полное письмо: тело, адреса, вложения. */
    public function full(Message $message, string $path): array
    {
        $html = Charset::fix($message->hasHTMLBody() ? $message->getHTMLBody() : null);
        $text = Charset::fix($message->hasTextBody() ? $message->getTextBody() : null);

        $attachments = [];
        $inline = [];
        // 157: ссылка на вложение ведёт по порядковому номеру, а здесь перебирались ключи
        // набора — библиотека нумерует их по частям письма и пропуски возможны. Тогда
        // «Скачать» отвечало «Not Found». Считаем номера так же, как их потом читают.
        foreach ($message->getAttachments()->values() as $i => $a) {
            /** @var Attachment $a */
            $cid = trim((string) ($a->id ?? ''), '<>');
            $isInline = $cid !== '' && $html && str_contains($html, 'cid:' . $cid);
            // Картинку до 2 МБ вшиваем в письмо строкой data:. Более тяжёлую подставлять нельзя
            // (страница раздувается), но и прятать её нельзя: раньше она оставалась битой ссылкой
            // в тексте и при этом исчезала из списка вложений — открыть её было нечем.
            $heavy = $isInline && $a->getSize() >= 2_000_000;
            if ($isInline && ! $heavy) {
                $inline['cid:' . $cid] = 'data:' . $a->getMimeType() . ';base64,' . base64_encode($a->getContent());
            } elseif ($heavy) {
                $inline['cid:' . $cid] = '/mail/api/message/' . rawurlencode($path) . '/' . $message->getUid() . '/attachment/' . $i . '?inline=1';
            }
            $attachments[] = [
                'index' => $i,
                'name' => Mime::attachmentName($a, 'вложение-' . ($i + 1)),
                // getSize() — размер в base64 из структуры письма; получателю нужен размер самого файла.
                'size' => strlen((string) $a->getContent()) ?: $a->getSize(),
                'type' => $a->getMimeType(),
                'inline' => $isInline && ! $heavy,
            ];
        }
        if ($html && $inline) {
            $html = strtr($html, $inline);
        }

        // Все Message-ID цепочки, каждый в <…> через пробел. Kerio пишет их слитно («<a><b>»), библиотека
        // отдаёт по-разному — без нормализации при ответе получался склеенный «a@xb@y», и письмо не уходило.
        // Библиотека при разборе склеивает id без пробела в один («a@xb@y»), поэтому берём сырой заголовок.
        $rawHeader = (string) ($message->getHeader()?->raw ?? '');
        $refIds = Mime::messageIds(Mime::headerValue($rawHeader, 'References') ?? $message->getReferences()->toArray());
        $refs = implode(' ', array_map(fn ($id) => '<' . $id . '>', $refIds));
        $inReplyTo = Mime::messageIds(Mime::headerValue($rawHeader, 'In-Reply-To') ?? $message->getInReplyTo()->toArray())[0] ?? '';

        return $this->summary($message) + [
            'folder' => $path,
            'html' => $html ? MailHtml::sanitize($html) : null,
            'text' => $text,
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
            // Скрытая копия нужна, чтобы черновик открывался тем же письмом, каким его сохранили.
            'bcc' => $this->addresses($message->getBcc()),
            'replyTo' => $this->addresses($message->getReplyTo()),
            'inReplyTo' => $inReplyTo,
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
            $this->threadHidden = max(0, ThreadIndex::lastTotal() - count($members));
            $found = [];
            $byFolder = [];
            foreach ($members as $m) {
                $byFolder[$m['folder']][] = $m['uid'];
            }
            foreach ($byFolder as $p => $uids) {
                $got = [];
                // Письмо из «Корзины» или «Спама» в переписке показывать надо, но так,
                // чтобы было видно, откуда оно: иначе непонятно, почему его нет в папке.
                $role = $this->tree->folderRole($p);
                $title = $this->folderTitle($p);
                try {
                    // Только заголовки и превью: свёрнутому письму в цепочке больше не нужно, тело подгрузится при раскрытии.
                    $this->client->openFolder($p, true);
                    $previews = $this->previews($uids);
                    foreach ($this->folder($p)->query()->whereUidIn($uids)->setFetchBody(false)->setFetchFlags(true)->get() as $m) {
                        $uid = (int) $m->getUid();
                        $got[] = $uid;
                        $found[] = $this->summary($m, $previews[$uid] ?? null)
                            + ['folder' => $p, 'folderRole' => $role, 'folderName' => $title, 'text' => (string) ($previews[$uid] ?? ''), 'to' => [], 'cc' => [], 'attachments' => [], 'html' => null, 'light' => true, 'thread' => []];
                    }
                } catch (\Throwable) {
                    continue;
                }
                if ($missing = array_diff($uids, $got)) {
                    ThreadIndex::forget($this->user(), $p, $missing); // письмо удалили или переложили — индекс подчистим
                }
            }
            // 393: часть программ (и выгрузки из 1С) не ставят ссылку на предыдущее
            // письмо. Если по ссылкам ничего не нашлось — пробуем по теме и собеседнику.
            if (! $found) {
                $found = $this->threadBySubject($message, $path);
            }
            usort($found, fn ($a, $b) => Mime::sortTime($a['date']) <=> Mime::sortTime($b['date']));

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
        $criteria = Mime::orCriteria($terms);

        $found = [];
        // Раньше искали только в текущей папке, «Отправленных» и «Входящих»: ответы,
        // разложенные правилами по проектным папкам, в переписку не попадали.
        // Ищем по своим папкам целиком, кроме спама и корзины, но не больше двенадцати —
        // это запасной путь, обычно работает индекс цепочек.
        $paths = [$path, $this->rolePath('sent'), $this->rolePath('inbox')];
        foreach ($this->folders() as $f) {
            if (! in_array($f['role'] ?? '', ['spam', 'trash', 'shared'], true)) {
                $paths[] = $f['path'];
            }
        }
        $paths = array_slice(array_values(array_unique(array_filter($paths))), 0, 12);
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
                // Только заголовки и превью: раньше здесь тянулись тела и все вложения
                // до шестидесяти писем разом, и на длинной переписке запрос отваливался по времени.
                $previews = $this->previews($uids);
                foreach ($folder->query()->whereUidIn($uids)->setFetchBody(false)->setFetchFlags(true)->get() as $m) {
                    $mid = trim((string) ($m->getMessageId()->first() ?? ''), '<>');
                    $key = $mid !== '' ? $mid : $p . '#' . $m->getUid();
                    if ($mid === $id || isset($found[$key])) {
                        continue;
                    }
                    $uidN = (int) $m->getUid();
                    $found[$key] = $this->summary($m, $previews[$uidN] ?? null)
                        + ['folder' => $p, 'text' => (string) ($previews[$uidN] ?? ''), 'to' => [], 'cc' => [], 'attachments' => [], 'html' => null, 'light' => true, 'thread' => []];
                }
            } catch (\Throwable) {
                // папка без нужных заголовков или сервер не поддерживает — пропускаем
            }
        }

        if (! $found) {
            $found = $this->threadBySubject($message, $path);
        }
        usort($found, fn ($a, $b) => Mime::sortTime($a['date']) <=> Mime::sortTime($b['date']));

        return array_values($found);
    }

    /** Тема без «Re:», «Fwd:», «Ответ:» и прочих приставок — по ней склеиваем переписку. */
    private static function bareSubject(string $subject): string
    {
        $s = trim($subject);
        // Приставки повторяются («Re: Fw: Re: …»), поэтому снимаем их по кругу.
        while (preg_match('/^\s*(re|fw|fwd|ответ|пересылка|вх|исх)\s*(\[\d+\])?\s*:\s*/iu', $s, $m)) {
            $s = mb_substr($s, mb_strlen($m[0]));
        }

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /**
     * Запасная склейка по теме: часть почтовых программ и выгрузки из 1С не проставляют
     * ссылку на предыдущее письмо, и переписка рассыпалась на отдельные письма.
     *
     * Чтобы не склеить чужое, требуем совпадения не только темы, но и собеседника:
     * у писем должен быть общий адрес. Ищем в текущей папке и в «Отправленных».
     *
     * @return array<int,array<string,mixed>>
     */
    private function threadBySubject(Message $message, string $path): array
    {
        // Заголовок приходит закодированным (=?windows-1251?B?…?=) — сравнивать и искать
        // надо по человеческому тексту, иначе запрос уходит на сервер абракадаброй.
        $bare = self::bareSubject((string) Charset::header((string) ($message->getSubject()->first() ?? '')));
        // Слишком короткая или слишком общая тема («Счёт», «Привет») склеит что попало.
        if (mb_strlen($bare) < 8) {
            return [];
        }
        $mine = [];
        foreach (['getFrom', 'getTo', 'getCc'] as $get) {
            foreach ($this->addresses($message->{$get}()) as $a) {
                $mine[strtolower($a['mail'])] = true;
            }
        }
        $uid = (int) $message->getUid();
        $found = [];
        foreach (array_slice(array_unique([$path, $this->rolePath('sent')]), 0, 2) as $p) {
            try {
                $this->client->openFolder($p, true);
                $role = $this->tree->folderRole($p);
                $title = $this->folderTitle($p);
                $uids = (array) $this->client->getConnection()->search(['SUBJECT', '"' . str_replace('"', '', $bare) . '"'])->validatedData();
                $uids = array_values(array_filter(array_map('intval', $uids), fn ($u) => $u > 0 && ! ($p === $path && $u === $uid)));
                if ($uids === []) {
                    continue;
                }
                $uids = array_slice($uids, -15);
                $previews = $this->previews($uids);
                foreach ($this->folder($p)->query()->whereUidIn($uids)->setFetchBody(false)->setFetchFlags(true)->get() as $m) {
                    if (self::bareSubject((string) Charset::header((string) ($m->getSubject()->first() ?? ''))) !== $bare) {
                        continue;   // сервер ищет подстроку — сверяем тему целиком
                    }
                    $common = false;
                    foreach (['getFrom', 'getTo', 'getCc'] as $get) {
                        foreach ($this->addresses($m->{$get}()) as $a) {
                            if (isset($mine[strtolower($a['mail'])])) {
                                $common = true;
                            }
                        }
                    }
                    if (! $common) {
                        continue;   // та же тема, но другие люди — это не наша переписка
                    }
                    $u = (int) $m->getUid();
                    $found[$p . '#' . $u] = $this->summary($m, $previews[$u] ?? null)
                        + ['folder' => $p, 'folderRole' => $role, 'folderName' => $title, 'text' => (string) ($previews[$u] ?? ''), 'to' => [], 'cc' => [], 'attachments' => [], 'html' => null, 'light' => true, 'thread' => [], 'bySubject' => true];
                }
            } catch (\Throwable) {
                // поиск по теме — подспорье, а не обязанность: молчим
            }
        }
        usort($found, fn ($a, $b) => Mime::sortTime($a['date']) <=> Mime::sortTime($b['date']));

        return array_values($found);
    }

    public function attachment(string $path, int $uid, int $index): Attachment
    {
        $message = $this->folder($path)->query()->getMessageByUid($uid);
        if (! $message) {
            throw MailException::notFound('Письмо не найдено');
        }
        $list = $message->getAttachments()->values();
        if (! isset($list[$index])) {
            throw MailException::notFound('Вложение не найдено');
        }

        return $list[$index];
    }

    /**
     * Все вложения письма одним ZIP (встроенные картинки из тела не берём). Возвращает путь к временному файлу,
     * имя для скачивания и число файлов; временный файл удаляет вызывающий (deleteFileAfterSend).
     *
     * @return array{path:string,name:string,count:int}
     */
    /** Расширение файла по типу — для вложений, у которых нет имени. */
    private const EXT_BY_TYPE = [
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/bmp' => 'bmp', 'image/tiff' => 'tif', 'image/heic' => 'heic',
        'image/svg+xml' => 'svg', 'image/x-icon' => 'ico',
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/html' => 'html', 'text/csv' => 'csv',
        'text/xml' => 'xml', 'application/xml' => 'xml', 'application/json' => 'json', 'text/rtf' => 'rtf',
        'application/rtf' => 'rtf',
        'message/rfc822' => 'eml',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/zip' => 'zip', 'application/x-zip-compressed' => 'zip',
        'application/x-rar-compressed' => 'rar', 'application/vnd.rar' => 'rar',
        'application/x-7z-compressed' => '7z', 'application/gzip' => 'gz',
        'application/vnd.ms-outlook' => 'msg',
        'audio/mpeg' => 'mp3', 'video/mp4' => 'mp4', 'audio/ogg' => 'ogg',
    ];

    public function attachmentsZip(string $path, int $uid): array
    {
        $message = $this->messageOrNull($path, $uid);
        if (! $message) {
            throw MailException::notFound('Письмо не найдено — возможно, его удалили или переложили в другой вкладке');
        }
        $html = (string) ($message->getHTMLBody() ?? '');
        $tmp = tempnam(sys_get_temp_dir(), 'att');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw MailException::upstream('Не удалось создать архив');
        }
        $used = [];
        $count = 0;
        // Нумерация та же, что в списке вложений письма (см. 157).
        foreach ($message->getAttachments()->values() as $i => $a) {
            /** @var Attachment $a */
            $cid = trim((string) ($a->id ?? ''), '<>');
            if ($cid !== '' && $html !== '' && str_contains($html, 'cid:' . $cid)) {
                continue;   // картинка из тела письма
            }
            $name = Mime::attachmentName($a, 'вложение-' . ($i + 1));
            $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1f]+#', '_', $name) ?: 'вложение-' . ($i + 1);
            // Часть без имени (библиотека подставляет кусок Content-ID) — добавим расширение по типу, чтобы файл открывался
            if (! str_contains($name, '.')) {
                // Таблица знала шесть типов, и вложенное письмо, docx, xlsx или zip попадали
                // в архив как «вложение-1», который Windows не открывает.
                $ext = self::EXT_BY_TYPE[strtolower((string) $a->getMimeType())] ?? null;
                if ($ext) {
                    $name .= '.' . $ext;
                }
            }
            // Одинаковые имена — нумеруем, иначе ZIP молча перезапишет
            $base = $name;
            for ($n = 2; isset($used[mb_strtolower($name)]); $n++) {
                $dot = strrpos($base, '.');
                $name = $dot ? substr($base, 0, $dot) . " ($n)" . substr($base, $dot) : "$base ($n)";
            }
            $used[mb_strtolower($name)] = true;
            $zip->addFromString($name, (string) $a->getContent());
            $count++;
        }
        $zip->close();
        $subject = trim((string) Charset::header((string) ($message->getSubject()->first() ?? '')));
        $subject = preg_replace('#[\\\\/:*?"<>|\x00-\x1f]+#', ' ', $subject) ?: '';
        $file = trim(mb_substr($subject, 0, 60)) ?: 'письмо-' . $uid;

        return ['path' => $tmp, 'name' => 'Вложения — ' . $file . '.zip', 'count' => $count];
    }

    /**
     * Предпросмотр офисного вложения: LibreOffice (headless) переводит документ в PDF, результат кэшируется по
     * содержимому файла (storage/app/private/preview, чистится раз в сутки старше недели). Преобразования идут
     * по одному — процессор слабый, а конвертер прожорливый. Возвращает путь к PDF.
     */
    public function attachmentPreviewPdf(string $path, int $uid, int $index): string
    {
        $a = $this->attachment($path, $uid, $index);
        $content = (string) $a->getContent();
        if ($content === '') {
            throw MailException::notFound('Вложение пустое');
        }
        if (strlen($content) > 25 * 1024 * 1024) {
            throw MailException::tooLarge('Документ слишком большой для предпросмотра — скачайте его');
        }
        $name = Mime::attachmentName($a, 'document');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'], true)) {
            throw MailException::unsupported('Этот тип файла не показываем — скачайте его');
        }
        $dir = storage_path('app/private/preview');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $pdf = $dir . '/' . sha1($content) . '.pdf';
        if (is_file($pdf) && filesize($pdf) > 0) {
            touch($pdf);

            return $pdf;
        }
        $lock = \Illuminate\Support\Facades\Cache::lock('office-preview', 90);
        if (! $lock->block(60)) {
            throw MailException::busy('Конвертер занят — попробуйте через минуту');
        }
        try {
            if (is_file($pdf) && filesize($pdf) > 0) {   // пока ждали, сделал кто-то другой
                return $pdf;
            }
            $work = $dir . '/tmp-' . bin2hex(random_bytes(6));
            mkdir($work, 0750, true);
            $src = $work . '/in.' . $ext;
            file_put_contents($src, $content);
            // Свой профиль в каталоге кэша: у www-data нет домашней папки, без профиля soffice не стартует.
            $cmd = ['soffice', '-env:UserInstallation=file://' . $dir . '/profile', '--headless', '--norestore', '--convert-to', 'pdf', '--outdir', $work, $src];
            $p = new \Symfony\Component\Process\Process($cmd, $work, ['HOME' => $dir], null, 120);
            $p->run();
            $out = $work . '/in.pdf';
            if (! $p->isSuccessful() || ! is_file($out)) {
                \Illuminate\Support\Facades\Log::warning('office-preview: ' . $name . ': ' . trim($p->getErrorOutput() . ' ' . $p->getOutput()));
                \Illuminate\Support\Facades\File::deleteDirectory($work);
                throw MailException::upstream('Не удалось подготовить предпросмотр — скачайте документ');
            }
            rename($out, $pdf);
            \Illuminate\Support\Facades\File::deleteDirectory($work);

            return $pdf;
        } finally {
            $lock->release();
        }
    }

    /** Заголовки одного письма как текст: нужны, чтобы восстановить отметки черновика. */
    /**
     * Письмо по номеру или null. Библиотека на отсутствующий UID бросает исключение о заголовках,
     * и наружу это выходило ошибкой сервера вместо понятного «письма больше нет».
     */
    private function messageOrNull(string $path, int $uid): ?Message
    {
        try {
            return $this->folder($path)->query()->getMessageByUid($uid);
        } catch (\Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function headersText(string $path, int $uid): string
    {
        try {
            $message = $this->folder($path)->query()->setFetchBody(false)->getMessageByUid($uid);
        } catch (\Throwable) {
            return '';
        }

        return $message ? (string) $message->getHeader()?->raw : '';
    }

    public function raw(string $path, int $uid): string
    {
        $message = $this->messageOrNull($path, $uid);
        if (! $message) {
            throw MailException::notFound('Письмо не найдено — возможно, его удалили или переложили в другой вкладке');
        }

        return (string) $message->getHeader()?->raw . "\r\n\r\n" . $message->getRawBody();
    }

    // ── Действия ─────────────────────────────────────────────────────────

    /** @var array<string,string> права на папки за время запроса (MYRIGHTS) */
    private array $rightsCache = [];

    /**
     * Права на папку по ACL. Буквы RFC 4314: s — менять «прочитано», t — «удалено»,
     * w — остальные пометки и метки, e — очищать папку.
     * Сервер без ACL или своя папка — считаем, что можно всё.
     */
    private function rights(string $path): string
    {
        if (isset($this->rightsCache[$path])) {
            return $this->rightsCache[$path];
        }
        $all = 'acdeilprstwx';
        try {
            $conn = $this->client->getConnection();
            $r = $conn->requestAndResponse('MYRIGHTS', [$conn->escapeString($path)]);
            $line = implode(' ', array_map(fn ($x) => is_array($x) ? implode(' ', array_map('strval', $x)) : (string) $x, (array) $r->getResponse()));
            $rights = preg_match('/MYRIGHTS\s+\S+\s+([a-zA-Z]+)/i', $line, $m) ? $m[1] : $all;
        } catch (\Throwable) {
            $rights = $all;
        }

        return $this->rightsCache[$path] = $rights;
    }

    /** Установить или снять флаг у набора писем (\Seen, \Flagged, \Answered, Lbl_N …). */
    public function flag(string $path, array $uids, string $flag, bool $on): void
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        if (! $uids) {
            return;
        }
        // Дело не только в отказах: в общей папке «только для просмотра» Dovecot отвечает на
        // команду OK, но пометку не сохраняет (её нет в PERMANENTFLAGS). Веб-почта показывала
        // успех, а через секунду письмо снова было непрочитанным. Спрашиваем права заранее.
        $need = match (strtolower($flag)) {
            '\\seen' => 's',
            '\\deleted' => 't',
            default => 'w',
        };
        $rights = $this->rights($path);
        if ($rights !== '' && ! str_contains(strtolower($rights), $need)) {
            throw MailException::denied(match ($need) {
                's' => 'Отметить прочитанным нельзя: владелец открыл эту папку только для просмотра.',
                't' => 'Удалить письмо отсюда нельзя: владелец открыл эту папку только для просмотра.',
                default => 'Поставить пометку или метку здесь нельзя: владелец открыл эту папку только для просмотра.',
            });
        }
        $this->client->openFolder($path, true);
        $conn = $this->client->getConnection();
        foreach (array_chunk($uids, 200) as $chunk) {
            // Библиотечный store() умеет только диапазоны, а нам нужен произвольный набор UID.
            $r = $conn->requestAndResponse('UID STORE', [implode(',', $chunk), ($on ? '+' : '-') . 'FLAGS.SILENT', $conn->escapeList([$flag])]);
            // Ответ сервера не проверялся: в общей папке «только для просмотра» отметка
            // «прочитано» возвращала успех, а через секунду письмо снова было непрочитанным.
            Mime::assertOk($r, 'Не удалось изменить пометку письма');
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
        $this->tree->forgetCache();
        ThreadIndex::touch($this, [$path, $target]);
    }

    /** Удалить: из корзины — навсегда, откуда угодно ещё — в корзину. */
    public function delete(string $path, array $uids): void
    {
        $trash = $this->rolePathFor($path, 'trash');
        if ($path === $trash) {
            $this->flag($path, $uids, '\\Deleted', true);
            $this->client->openFolder($path, true);
            // UID EXPUNGE убирает ровно выбранные письма. Обычный EXPUNGE сносит из папки всё
            // помеченное к удалению — в том числе то, что человек пометил в Outlook или на телефоне
            // и там ещё видит: такие письма исчезали навсегда.
            $this->expungeUids($this->client->getConnection(), array_values(array_unique(array_map('intval', $uids))));
            // Иначе панель папок ещё минуту показывает старое «Корзина (12)»: кэш списка папок
            // сбрасывают move() и emptyFolder(), а эта ветка — нет.
            $this->tree->forgetCache();

            return;
        }
        $this->move($path, $uids, $trash);
    }

    public function emptyFolder(string $path): void
    {
        $rights = strtolower($this->rights($path));
        if ($rights !== '' && (! str_contains($rights, 't') || ! str_contains($rights, 'e'))) {
            throw MailException::denied('Очистить эту папку нельзя: владелец открыл её только для просмотра.');
        }
        $this->client->openFolder($path, true);
        $conn = $this->client->getConnection();
        $r = $conn->requestAndResponse('STORE', ['1:*', '+FLAGS.SILENT', $conn->escapeList(['\\Deleted'])]);
        // Результат не проверялся, и при отказе сервера человек получал сообщение об успехе.
        Mime::assertOk($r, 'Не удалось очистить папку');
        $conn->expunge();
        $this->tree->forgetCache();
    }

    /**
     * Убрать из папки именно эти письма. Если сервер не умеет UIDPLUS, откатываемся
     * на обычный EXPUNGE — иначе письма останутся лежать помеченными к удалению.
     */
    private function expungeUids(mixed $conn, array $uids): void
    {
        if ($uids === []) {
            return;
        }
        // Пробуем UID EXPUNGE (RFC 4315). Сервер без него ответит BAD — тогда обычный EXPUNGE:
        // проверять возможности отдельной командой ради одного удаления незачем.
        try {
            foreach (array_chunk($uids, 200) as $chunk) {
                $conn->requestAndResponse('UID EXPUNGE', [implode(',', $chunk)]);
            }
        } catch (\Throwable) {
            $conn->expunge();
        }
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
            $out[] = Mime::address($a->personal, $a->mail);
        }

        return $out;
    }

    // ── Поиск по IMAP для правил и решений по отправителям ─────────────

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
