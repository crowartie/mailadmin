<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * Списки писем: страница папки, поиск по папке и по всем папкам, порядок и отбор.
 *
 * Здесь же — обход особенностей сервера: сортировка через IMAP SORT с откатом на
 * внутренние номера, быстрый FETCH страницы вместо разбора каждого письма библиотекой
 * и переподключение после сбойного поиска (иначе соединение остаётся рассинхронным).
 */
final class MessageListing
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

    public function __construct(
        private readonly Client $client,
        private readonly FolderTree $tree,
        private readonly MessageSummary $summaries,
    ) {
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
        foreach ($this->tree->folders() as $f) {
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
                        $skipped[] = $this->tree->folderTitle($rest);
                    }
                    break;
                }
                try {
                    $q = $this->tree->folder($p)->query()->setFetchBody(false)->setFetchFlags(true);
                    (new SearchQuery($query))->apply($q);
                    $uids = $this->searchUids($q, $p);
                } catch (\Throwable) {
                    // Не роняем весь поиск, но и не делаем вид, что здесь ничего не нашлось.
                    $skipped[] = $this->tree->folderTitle($p);
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
                $previews = $this->summaries->previews($uids);
                foreach ($this->tree->folder($p)->query()->whereUidIn($uids)->setFetchBody(false)->setFetchFlags(true)->get() as $m) {
                    $row = $this->summaries->summary($m, $previews[(int) $m->getUid()] ?? null);
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
        $folder = $this->tree->folder($path);
        $page = max(1, $page);

        $q = $folder->query()->setFetchBody(false)->setFetchFlags(true)->setFetchOrder('desc');
        $q = $this->applyFilter($q, $filter);
        $searching = $query !== null && trim($query) !== '';
        $byFile = null;
        $needAttachment = false;
        if ($searching) {
            $sq = new SearchQuery($query);
            $q = $sq->apply($q);
            $byFile = $sq->fileName();
            $needAttachment = $sq->needsAttachment();
        }
        if (! $searching && $filter === 'all') {
            $q->all();
        }

        $messages = [];
        // Порядок писем задаёт дата письма, а не внутренний номер: письмо, перенесённое в папку
        // сегодня, получает самый большой номер и без сортировки встаёт наверх, даже если ему два года.
        $sorted = $this->sortedUids($searching || $filter !== 'all' ? $q : null, $path, $sort);
        if ($sorted !== null && $needAttachment) {
            // Отбор по вложениям делается после поиска, значит и после сортировки:
            // сервер о вложениях и их именах по заголовкам ничего не отвечает.
            $sorted = $this->keepWithFile($path, $sorted, $byFile);
        }
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
            if ($needAttachment) {
                $uids = $this->keepWithFile($path, $uids, $byFile);
            }
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
        $slowKey = $sort === 'date' ? 'sort-slow.' . md5($this->tree->user() . '|' . $path) : null;
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

    /**
     * Оставить из найденных писем те, где есть вложение с таким именем.
     *
     * Имя файла лежит в структуре письма и приходит закодированным, поэтому поиском
     * по тексту его не найти. Структуры запрашиваем одним обращением к серверу и
     * сверяем имена уже раскодированными.
     *
     * @param  int[]  $uids
     * @return int[]
     */
    private function keepWithFile(string $path, array $uids, ?string $needle): array
    {
        if (! $uids) {
            return [];
        }
        // Больше полутора тысяч писем разом смотреть незачем: это уже не поиск,
        // а перебор ящика. Берём самые свежие — список и так отсортирован от новых.
        $slice = array_slice($uids, 0, 1500);
        $need = $needle === null ? null : mb_strtolower(trim($needle));
        $out = [];
        foreach (Structure::many($this->client, $path, $slice) as $uid => $parts) {
            foreach (Structure::attachments($parts) as $a) {
                // Картинка из текста письма вложением не считается: иначе «есть:вложение»
                // находило бы каждое письмо с логотипом в подписи.
                if ($a['disposition'] === 'inline' && $a['id'] !== '') {
                    continue;
                }
                if ($need === null || ($a['name'] !== '' && str_contains(mb_strtolower($a['name']), $need))) {
                    $out[] = (int) $uid;
                    break;
                }
            }
        }
        rsort($out);

        return $out;
    }

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
            $out[] = $this->summaries->summaryFromFetch($row);
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
        $previews = $this->summaries->previews($uids);
        foreach ($uids as $uid) {
            try {
                $m = $q->getMessageByUid((int) $uid);
            } catch (\Throwable) {
                continue;
            }
            if ($m) {
                $messages[] = $this->summaries->summary($m, $previews[$uid] ?? null);
            }
        }

        return $messages;
    }

    /** Прежний путь: библиотека выбирает нужные UID и разбирает заголовки сама. */
    private function pageViaLibrary(WhereQuery $q, int $page): array
    {
        $messages = [];
        $pageMessages = $q->limit(self::PAGE, $page)->get()->sortByDesc(fn (Message $m) => $m->getUid());
        $previews = $this->summaries->previews($pageMessages->map(fn (Message $m) => $m->getUid())->values()->all());
        foreach ($pageMessages as $m) {
            $messages[] = $this->summaries->summary($m, $previews[$m->getUid()] ?? null);
        }

        return $messages;
    }

    /** UID писем начиная с заданного (для дочитывания новых). @return int[] */
    public function searchFrom(string $path, int $fromUid): array
    {
        $this->client->openFolder($path, true);
        $r = $this->client->getConnection()->search(['UID', $fromUid . ':*'], IMAP::ST_UID)->validatedData();

        return array_values(array_filter(array_map('intval', is_array($r) ? $r : []), fn ($u) => $u >= $fromUid));
    }
}
