<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * Спросить у Dovecot список UID — и не подвесить страницу, если он занят.
 *
 * Здесь всё, из-за чего поиск когда-то замолкал: свой срок ожидания на сокете,
 * переподключение после сбойного поиска (после него связь остаётся рассинхронной,
 * и следующий запрос читает чужой ответ) и разбор поисковых условий.
 */
class ImapQuery
{
    public function __construct(
        private readonly Client $client,
        private readonly FolderTree $tree,
    ) {
    }


    /**
     * Сколько ждём ответа на поиск в одной папке, секунд. Поиск по готовому индексу
     * укладывается в миллисекунды, так что трёх секунд хватает с запасом; библиотека
     * успевает сделать по молчащему сокету несколько чтений подряд, поэтому папка,
     * занятая индексацией, обходится примерно втрое дороже этого срока.
     */
    public const SEARCH_TIMEOUT = 3;


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


    /** Обычное ожидание ответа сервера — запоминаем при первой смене. */
    private ?int $timeout = null;


    public function sortedUids(?WhereQuery $q, string $path, string $sort = 'date'): ?array
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
    public function searchUids(WhereQuery $q, string $path, bool $searching = true): array
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
     * Нужен ли этому отбору разбор структуры писем.
     *
     * «Вложения» раньше отбирались поиском по заголовку Content-Type: multipart/mixed.
     * При fts_enforced = body Dovecot на поиск по заголовкам не отвечает ничем, и вкладка
     * молча показывала пустой список — во «Входящих» на две сотни писем тоже. Ровно так же
     * не работал оператор «есть:вложение», пока его не перевели на структуру письма.
     *
     * Заголовок и по сути не годится: multipart/mixed стоит у письма, где «вложение» —
     * картинка из подписи, и не стоит у письма с одним PDF без текста.
     */
    public function filterNeedsAttachment(string $filter): bool
    {
        return $filter === 'attach';
    }

    public function applyFilter(WhereQuery $q, string $filter): WhereQuery
    {
        if (str_starts_with($filter, 'label:')) {
            return $q->whereKeyword('Lbl_' . (int) substr($filter, 6));
        }

        return match ($filter) {
            'unread' => $q->unseen(),
            'flagged' => $q->where('FLAGGED'),   // ->flagged() в php-imap 6.2 требует аргумент и падает
            // «Вложения» сервером не отбираются: смотрите filterNeedsAttachment().
            'attach' => $q,
            default => $q,
        };
    }


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
    public function keepWithFile(string $path, array $uids, ?string $needle): array
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


    /**
     * Ждать ответа сервера не дольше заданного (null — вернуть обычное ожидание).
     * Срок задаётся сокету при подключении, поэтому соединение переустанавливаем.
     */
    public function withTimeout(?int $seconds): void
    {
        $was = $this->timeout ??= $this->client->timeout;
        $now = $seconds ?? $was;
        if ($now === $this->client->timeout) {
            return;
        }
        $this->client->timeout = $now;
        $this->reconnect();
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
}
