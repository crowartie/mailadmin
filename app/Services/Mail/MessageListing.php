<?php

namespace App\Services\Mail;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;

/**
 * Что показать человеку: страница папки, отбор, порядок и поиск.
 *
 * Механика вынесена вниз: ImapQuery знает, как спросить у сервера список UID, не
 * подвесив страницу, MessagePage — как превратить этот список в письма. Здесь остаются
 * решения: где искать, в каком порядке обходить папки, что делать, когда время вышло.
 */
class MessageListing
{
    private readonly ImapQuery $q;

    private readonly MessagePage $pages;

    public function __construct(
        private readonly Client $client,
        private readonly FolderTree $tree,
        private readonly MessageSummary $summaries,
    ) {
        $this->q = new ImapQuery($client, $tree);
        $this->pages = new MessagePage($client, $tree, $summaries);
    }

    /** @see MessagePage::PAGE — размер страницы задаёт тот, кто её собирает. */
    public const PAGE = MessagePage::PAGE;


    /** Сколько всего ждём поиск по всем папкам, секунд (срок проверяется между папками). */
    private const SEARCH_BUDGET = 12;


    // ── Списки ───────────────────────────────────────────────────────────

    /**
     * Поиск по всем папкам, которые видит сотрудник, — включая общие папки коллег,
     * «Спам» и «Корзину».
     *
     * Раньше эти три вида папок пропускались: считалось, что туда человек заглянет сам.
     * На деле выходило наоборот. Письмо, отправленное из общего ящика, лежит в его
     * «Отправленных» — то есть в общей папке, — и поиск отвечал «Найдено 0 во всех
     * папках». Человек читал это как «письмо пропало». Ровно так и случилось
     * с пересылкой из info@ 17 сентября.
     *
     * Порядок обхода важен: сначала папка, в которой человек стоит ($from), и свои,
     * потом общие, потом спам и корзина. Если сработает общий срок поиска, необойдённым
     * останется то, что ищут реже, и об этих папках поиск скажет прямо.
     *
     * @return array{messages:array,total:int,page:int,pages:int}
     */
    public function searchEverywhere(string $query, int $page = 1, string $sort = 'date', ?string $from = null): array
    {
        $page = max(1, $page);
        $own = [];
        $shared = [];
        $junk = [];
        foreach ($this->tree->folders() as $f) {
            $role = $f['role'] ?? '';
            if ($role === 'shared') {
                $shared[] = $f['path'];
            } elseif (in_array($role, ['spam', 'trash'], true)) {
                $junk[] = $f['path'];
            } else {
                $own[] = $f['path'];
            }
        }
        // Текущая папка и «Входящие» с «Отправленными» — первыми: там ищут чаще всего.
        $paths = array_merge([$from, $this->tree->rolePath('inbox'), $this->tree->rolePath('sent')], $own, $shared, $junk);
        // Ограничение на число папок: иначе на большом дереве это десятки поисков подряд.
        $paths = array_slice(array_values(array_unique(array_filter($paths))), 0, 25);

        $hits = [];
        $skipped = [];
        // Папка, которую сервер в этот момент индексирует, отвечает не сразу, а по таймауту.
        // Пятнадцать таких папок складывались в шесть минут — страница отваливалась раньше,
        // чем приходил ответ. Поэтому на время перебора ждём каждый ответ недолго и держим
        // общий срок: что успели — показываем, остальные папки честно называем.
        $deadline = microtime(true) + self::SEARCH_BUDGET;
        $this->q->withTimeout(ImapQuery::SEARCH_TIMEOUT);
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
                    $uids = $this->q->searchUids($q, $p);
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
            $this->q->withTimeout(null);
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
                    $row['folderName'] = $this->searchFolderName($p);
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


    /**
     * Страница списка. $filter: all|unread|flagged|attach. $query — строка поиска
     * с операторами (см. SearchQuery); при поиске страницы считаются по результату.
     *
     * @return array{messages:array,total:int,page:int,pages:int}
     */
    public function list(string $path, int $page = 1, string $filter = 'all', ?string $query = null, string $sort = 'date'): array
    {
        $folder = $this->tree->folder($path);
        $page = max(1, $page);

        $q = $folder->query()->setFetchBody(false)->setFetchFlags(true)->setFetchOrder('desc');
        $q = $this->q->applyFilter($q, $filter);
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
        // Вкладка «Вложения»: отбор по структуре письма, как и у оператора «есть:вложение».
        if ($this->q->filterNeedsAttachment($filter)) {
            $needAttachment = true;
            if (! $searching) {
                $q->all();
            }
        }

        $messages = [];
        // Порядок писем задаёт дата письма, а не внутренний номер: письмо, перенесённое в папку
        // сегодня, получает самый большой номер и без сортировки встаёт наверх, даже если ему два года.
        $sorted = $this->q->sortedUids($searching || $filter !== 'all' ? $q : null, $path, $sort);
        if ($sorted !== null && $needAttachment) {
            // Отбор по вложениям делается после поиска, значит и после сортировки:
            // сервер о вложениях и их именах по заголовкам ничего не отвечает.
            $sorted = $this->q->keepWithFile($path, $sorted, $byFile);
        }
        if ($sorted !== null) {
            $total = count($sorted);
            $slice = array_slice($sorted, ($page - 1) * self::PAGE, self::PAGE);
            $messages = $slice ? ($this->pages->pageFastUids($slice) ?? []) : [];
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
            $uids = $this->q->searchUids($q, $path, $searching);
            rsort($uids);
            if ($needAttachment) {
                $uids = $this->q->keepWithFile($path, $uids, $byFile);
            }
            $total = count($uids);
            $slice = array_slice($uids, ($page - 1) * self::PAGE, self::PAGE);
            $messages = $slice ? ($this->pages->pageFastUids($slice) ?? $this->pages->pageViaLibraryUids($q, $slice)) : [];
        } else {
            $total = (int) ($folder->examine()['exists'] ?? 0);
            if ($total > 0) {
                $messages = $this->pages->pageFast($page, $total) ?? $this->pages->pageViaLibrary($q, $page);
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
     * Название папки для строки найденного письма.
     *
     * Раньше оно собиралось из пути, и у общей папки выходило «su»: адрес владельца
     * (info@innotec.su) резался по точке — это правило для вложенных папок Maildir++,
     * к адресу оно не относится. А «Отправленные» свои и «Отправленные» общего ящика
     * выглядели одинаково, хотя лежат в разных ящиках.
     */
    private function searchFolderName(string $path): string
    {
        foreach ($this->tree->folders() as $f) {
            if ($f['path'] !== $path) {
                continue;
            }
            $name = (string) ($f['name'] ?? $path);

            return ($f['role'] ?? '') === 'shared'
                ? trim((string) ($f['ownerName'] ?? $f['owner'] ?? '')) . ' · ' . $name
                : $name;
        }

        return Mime::utf8Name(basename(str_replace('.', '/', $path))) ?: $path;
    }


    /** UID писем начиная с заданного (для дочитывания новых). @return int[] */
    public function searchFrom(string $path, int $fromUid): array
    {
        $this->client->openFolder($path, true);
        $r = $this->client->getConnection()->search(['UID', $fromUid . ':*'], IMAP::ST_UID)->validatedData();

        return array_values(array_filter(array_map('intval', is_array($r) ? $r : []), fn ($u) => $u >= $fromUid));
    }
}
