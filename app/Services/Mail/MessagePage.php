<?php

namespace App\Services\Mail;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * Превратить список UID в страницу писем.
 *
 * Быстрый путь — один FETCH на всю страницу; запасной — через библиотеку, если сервер
 * ответил не так, как мы разбираем. Оба пути живут рядом, чтобы было видно, что
 * запасной делает ровно то же самое, а не «примерно похоже».
 */
class MessagePage
{
    /** Сколько писем на странице. Живёт здесь: страницы собирает этот класс. */
    public const PAGE = 40;

    public function __construct(
        private readonly Client $client,
        private readonly FolderTree $tree,
        private readonly MessageSummary $summaries,
    ) {
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
    public function pageFast(int $page, int $total): ?array
    {
        $hi = $total - ($page - 1) * self::PAGE;
        if ($hi < 1) {
            return [];
        }
        $lo = max(1, $hi - self::PAGE + 1);

        return $this->fetchPage($lo, $hi, IMAP::ST_MSGN, $hi - $lo + 1);
    }


    /** То же для заранее известных UID (результат поиска или фильтра). */
    public function pageFastUids(array $uids): ?array
    {
        return $this->fetchPage(array_values($uids), null, IMAP::ST_UID, count($uids));
    }


    /** @return array<int,array<string,mixed>>|null */
    private function fetchPage(int|array $from, ?int $to, int $mode, int $expected): ?array
    {
        $items = ['UID', 'FLAGS', 'RFC822.SIZE', 'INTERNALDATE', 'PREVIEW', 'BODY.PEEK[HEADER.FIELDS (FROM SENDER REPLY-TO RETURN-PATH TO DATE SUBJECT MESSAGE-ID CONTENT-TYPE)]'];
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
    public function pageViaLibraryUids(WhereQuery $q, array $uids): array
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
    public function pageViaLibrary(WhereQuery $q, int $page): array
    {
        $messages = [];
        $pageMessages = $q->limit(self::PAGE, $page)->get()->sortByDesc(fn (Message $m) => $m->getUid());
        $previews = $this->summaries->previews($pageMessages->map(fn (Message $m) => $m->getUid())->values()->all());
        foreach ($pageMessages as $m) {
            $messages[] = $this->summaries->summary($m, $previews[$m->getUid()] ?? null);
        }

        return $messages;
    }
}
