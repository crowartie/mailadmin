<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/** Переписка: собрать письма одной цепочки по ссылкам, а где их нет — по теме и собеседнику. */
class ThreadBuilder
{
    public function __construct(
        private readonly Client $client,
        private readonly FolderTree $tree,
        private readonly MessageSummary $summaries,
    ) {
    }

    /** Сколько писем переписки осталось за пределами показанного (см. threadOf). */
    public int $threadHidden = 0;


    /** Цепочка ответов для уже открытого письма. */
    public function threadOf(string $path, int $uid): array
    {
        try {
            // Для цепочки нужны только заголовки письма — тело не тянем.
            $message = $this->tree->folder($path)->query()->setFetchBody(false)->setFetchFlags(false)->getMessageByUid($uid);
        } catch (\Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException) {
            $message = null;
        }
        if (! $message) {
            throw MailException::notFound('Письмо не найдено');
        }

        return $this->thread($message, $path);
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
        $members = ThreadIndex::threadOf($this->tree->user(), $path, (int) $message->getUid());
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
                $title = $this->tree->folderTitle($p);
                try {
                    // Только заголовки и превью: свёрнутому письму в цепочке больше не нужно, тело подгрузится при раскрытии.
                    $this->client->openFolder($p, true);
                    $previews = $this->summaries->previews($uids);
                    foreach ($this->tree->folder($p)->query()->whereUidIn($uids)->setFetchBody(false)->setFetchFlags(true)->get() as $m) {
                        $uid = (int) $m->getUid();
                        $got[] = $uid;
                        $found[] = $this->summaries->summary($m, $previews[$uid] ?? null)
                            + ['folder' => $p, 'folderRole' => $role, 'folderName' => $title, 'text' => (string) ($previews[$uid] ?? ''), 'to' => [], 'cc' => [], 'attachments' => [], 'html' => null, 'light' => true, 'thread' => []];
                    }
                } catch (\Throwable) {
                    continue;
                }
                if ($missing = array_diff($uids, $got)) {
                    ThreadIndex::forget($this->tree->user(), $p, $missing); // письмо удалили или переложили — индекс подчистим
                }
            }
            // 393: часть программ (и выгрузки из 1С) не ставят ссылку на предыдущее
            // письмо. Если по ссылкам ничего не нашлось — пробуем по теме и собеседнику.
            //
            // Но только если ссылки и правда нет. Раньше поиск по теме шёл всегда, и каждое
            // письмо без переписки — а таких большинство — стоило лишних 116 мс при открытии,
            // хотя индекс цепочек уже ответил определённо. Есть ссылка и по ней пусто —
            // значит, переписки нет.
            if (! $found && ! $ids) {
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
        $paths = [$path, $this->tree->rolePath('sent'), $this->tree->rolePath('inbox')];
        foreach ($this->tree->folders() as $f) {
            if (! in_array($f['role'] ?? '', ['spam', 'trash', 'shared'], true)) {
                $paths[] = $f['path'];
            }
        }
        $paths = array_slice(array_values(array_unique(array_filter($paths))), 0, 12);
        foreach ($paths as $p) {
            try {
                $folder = $this->tree->folder($p);
                $this->client->openFolder($p, true);
                $uids = (array) $this->client->getConnection()->search($criteria)->validatedData();
                $uids = array_values(array_filter(array_map('intval', $uids), fn ($u) => $u > 0 && ! ($p === $path && $u === (int) $message->getUid())));
                if ($uids === []) {
                    continue;
                }
                $uids = array_slice($uids, -20);
                // Только заголовки и превью: раньше здесь тянулись тела и все вложения
                // до шестидесяти писем разом, и на длинной переписке запрос отваливался по времени.
                $previews = $this->summaries->previews($uids);
                foreach ($folder->query()->whereUidIn($uids)->setFetchBody(false)->setFetchFlags(true)->get() as $m) {
                    $mid = trim((string) ($m->getMessageId()->first() ?? ''), '<>');
                    $key = $mid !== '' ? $mid : $p . '#' . $m->getUid();
                    if ($mid === $id || isset($found[$key])) {
                        continue;
                    }
                    $uidN = (int) $m->getUid();
                    $found[$key] = $this->summaries->summary($m, $previews[$uidN] ?? null)
                        + ['folder' => $p, 'text' => (string) ($previews[$uidN] ?? ''), 'to' => [], 'cc' => [], 'attachments' => [], 'html' => null, 'light' => true, 'thread' => []];
                }
            } catch (\Throwable) {
                // папка без нужных заголовков или сервер не поддерживает — пропускаем
            }
        }

        // Условие то же, что и на пути через индекс: иначе одно и то же письмо склеивалось бы
        // в переписку по-разному в зависимости от того, попало оно в индекс или нет.
        if (! $found && ! $ids) {
            $found = $this->threadBySubject($message, $path);
        }
        usort($found, fn ($a, $b) => Mime::sortTime($a['date']) <=> Mime::sortTime($b['date']));

        return array_values($found);
    }


    /** Приставка ответа или пересылки в начале темы: «Re:», «Fwd:», «Ответ:»… */
    private const REPLY_PREFIX = '/^\s*(re|fw|fwd|ответ|пересылка|вх|исх)\s*(\[\d+\])?\s*:/iu';

    /** Автоматический адрес: такие письма — уведомления, а не переписка (как NOREPLY в веб-почте). */
    private const AUTOMATIC = '/^(no[-_.]?reply|do[-_.]?not[-_.]?reply|mailer[-_.]?daemon|bounce[sd]?|postmaster|nobody|notifications?)@/i';

    /**
     * Склеивать ли по теме два письма без ссылок друг на друга. Только письмо и ответ на него
     * («Счёт 15» и «Re: Счёт 15») или ответы между собой. Два самостоятельных письма с одной темой —
     * уведомления «Вход в почту с нового устройства», ежедневные отчёты, рассылки — не переписка:
     * раньше они собирались в одну «цепочку» из десятка писем (26.09.2026, приложение и веб).
     */
    public static function sameConversationBySubject(string $a, string $b): bool
    {
        if (self::bareSubject($a) !== self::bareSubject($b) || mb_strlen(self::bareSubject($a)) < 8) {
            return false;
        }

        return (bool) preg_match(self::REPLY_PREFIX, $a) || (bool) preg_match(self::REPLY_PREFIX, $b);
    }

    public static function automaticSender(string $mail): bool
    {
        return (bool) preg_match(self::AUTOMATIC, trim($mail));
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
        $subject = (string) Charset::header((string) ($message->getSubject()->first() ?? ''));
        $bare = self::bareSubject($subject);
        // Слишком короткая или слишком общая тема («Счёт», «Привет») склеит что попало.
        if (mb_strlen($bare) < 8) {
            return [];
        }
        // Уведомления с автоматических адресов переписки не образуют — не склеиваем их по теме вовсе.
        foreach (MailAddresses::of($message->getFrom()) as $a) {
            if (self::automaticSender($a['mail'])) {
                return [];
            }
        }
        $mine = [];
        foreach (['getFrom', 'getTo', 'getCc'] as $get) {
            foreach (MailAddresses::of($message->{$get}()) as $a) {
                $mine[strtolower($a['mail'])] = true;
            }
        }
        $uid = (int) $message->getUid();
        $found = [];
        foreach (array_slice(array_unique([$path, $this->tree->rolePath('sent')]), 0, 2) as $p) {
            try {
                $this->client->openFolder($p, true);
                $role = $this->tree->folderRole($p);
                $title = $this->tree->folderTitle($p);
                $uids = (array) $this->client->getConnection()->search(['SUBJECT', '"' . str_replace('"', '', $bare) . '"'])->validatedData();
                $uids = array_values(array_filter(array_map('intval', $uids), fn ($u) => $u > 0 && ! ($p === $path && $u === $uid)));
                if ($uids === []) {
                    continue;
                }
                $uids = array_slice($uids, -15);
                $previews = $this->summaries->previews($uids);
                foreach ($this->tree->folder($p)->query()->whereUidIn($uids)->setFetchBody(false)->setFetchFlags(true)->get() as $m) {
                    // Сервер ищет подстроку — сверяем тему целиком; и склеиваем только письмо с ответами на него.
                    if (! self::sameConversationBySubject($subject, (string) Charset::header((string) ($m->getSubject()->first() ?? '')))) {
                        continue;
                    }
                    $common = false;
                    foreach (['getFrom', 'getTo', 'getCc'] as $get) {
                        foreach (MailAddresses::of($m->{$get}()) as $a) {
                            if (isset($mine[strtolower($a['mail'])])) {
                                $common = true;
                            }
                        }
                    }
                    if (! $common) {
                        continue;   // та же тема, но другие люди — это не наша переписка
                    }
                    $u = (int) $m->getUid();
                    $found[$p . '#' . $u] = $this->summaries->summary($m, $previews[$u] ?? null)
                        + ['folder' => $p, 'folderRole' => $role, 'folderName' => $title, 'text' => (string) ($previews[$u] ?? ''), 'to' => [], 'cc' => [], 'attachments' => [], 'html' => null, 'light' => true, 'thread' => [], 'bySubject' => true];
                }
            } catch (\Throwable) {
                // поиск по теме — подспорье, а не обязанность: молчим
            }
        }
        usort($found, fn ($a, $b) => Mime::sortTime($a['date']) <=> Mime::sortTime($b['date']));

        return array_values($found);
    }
}
