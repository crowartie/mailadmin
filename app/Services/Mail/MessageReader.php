<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Чтение письма: тело, вложения, переписка, печать и скачивание частей.
 *
 * Письмо открывается, не скачиваясь целиком: состав берётся из структуры (Structure),
 * а тело дочитывается отдельными частями. Старый путь — когда библиотека качает письмо
 * со всеми вложениями — остаётся запасным на случай, если структура не разобралась.
 */
final class MessageReader
{
    /** Сколько писем переписки осталось за пределами показанного (см. threadOf). */
    public int $threadHidden = 0;

    public function __construct(
        private readonly Client $client,
        private readonly FolderTree $tree,
        private readonly MessageSummary $summaries,
        private readonly MailActions $actions,
    ) {
    }

    // ── Чтение ───────────────────────────────────────────────────────────

    public function message(string $path, int $uid, bool $markSeen = true): array
    {
        // Сначала только заголовки: их хватает и для шапки письма, и для решения,
        // можно ли показать письмо, не скачивая вложения (см. fullLight).
        try {
            $message = $this->tree->folder($path)->query()->setFetchBody(false)->setFetchFlags(true)->getMessageByUid($uid);
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
            $heavy = $this->tree->folder($path)->query()->getMessageByUid($uid);
            if (! $heavy) {
                throw MailException::notFound('Письмо не найдено');
            }
            $data = $this->full($heavy, $path);
        }

        if ($markSeen && ! $message->getFlags()->has('seen')) {
            $this->actions->flag($path, [$uid], '\\Seen', true);
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
        $inline = [];
        foreach ($list as $i => $a) {
            $cid = (string) $a['id'];
            $isInline = $cid !== '' && $html !== null && str_contains($html, 'cid:' . $cid);
            if ($isInline) {
                // Картинку из текста письма не вшиваем в разметку строкой data:, а даём
                // ссылкой на себя же. Письмо с двумя десятками картинок иначе разрасталось
                // до шести мегабайт разметки, и одна только чистка занимала три секунды;
                // теперь картинки тянет браузер — параллельно и с кэшем.
                $inline['cid:' . $cid] = '/mail/api/message/' . rawurlencode($path) . '/' . $uid . '/attachment/' . $i . '?inline=1';
            }
            $attachments[] = [
                'index' => $i,
                'name' => $a['name'] !== '' ? $a['name'] : 'вложение-' . ($i + 1),
                'size' => $a['size'],
                'type' => $a['mime'],
                'inline' => $isInline,
            ];
        }
        // Подставляем до чистки: схему cid: чистка не пропускает, и картинки пропали бы.
        // Ссылка короткая, поэтому разметка остаётся маленькой.
        if ($html !== null && $inline) {
            $html = strtr($html, $inline);
        }
        $clean = $html !== null && $html !== '' ? MailHtml::sanitize($html) : null;

        $rawHeader = (string) ($message->getHeader()?->raw ?? '');
        $refIds = Mime::messageIds(Mime::headerValue($rawHeader, 'References') ?? $message->getReferences()->toArray());
        $refs = implode(' ', array_map(fn ($id) => '<' . $id . '>', $refIds));
        $inReplyTo = Mime::messageIds(Mime::headerValue($rawHeader, 'In-Reply-To') ?? $message->getInReplyTo()->toArray())[0] ?? '';

        return $this->summaries->summary($message) + [
            'folder' => $path,
            'html' => $clean,
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

        return $this->summaries->summary($message) + [
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

    /**
     * Одно вложение письма по его номеру.
     *
     * Сначала пробуем взять его одной частью: содержимое каждой картинки из текста
     * письма браузер запрашивает отдельно, и выкачивать ради неё письмо целиком (а с ним
     * и все прочие вложения) — это секунды и десятки мегабайт памяти на каждый запрос.
     */
    public function attachment(string $path, int $uid, int $index): MailPart
    {
        $parts = Structure::of($this->client, $path, $uid);
        if ($parts !== null) {
            $list = Structure::attachments($parts);
            if (! isset($list[$index])) {
                throw MailException::notFound('Вложение не найдено');
            }
            $a = $list[$index];
            $got = Structure::fetchParts($this->client, $path, $uid, [$a]);
            if (isset($got[$a['no']])) {
                $name = $a['name'] !== '' ? $a['name'] : 'вложение-' . ($index + 1);

                return new MailPart($name, (string) $a['mime'], $got[$a['no']]);
            }
        }

        // Структура не разобралась или часть не пришла — читаем письмо целиком, как раньше.
        $message = $this->tree->folder($path)->query()->getMessageByUid($uid);
        if (! $message) {
            throw MailException::notFound('Письмо не найдено');
        }
        $list = $message->getAttachments()->values();
        if (! isset($list[$index])) {
            throw MailException::notFound('Вложение не найдено');
        }

        return MailPart::fromAttachment($list[$index], 'вложение-' . ($index + 1));
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
        $name = $a->getName();
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
            return $this->tree->folder($path)->query()->getMessageByUid($uid);
        } catch (\Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function headersText(string $path, int $uid): string
    {
        try {
            $message = $this->tree->folder($path)->query()->setFetchBody(false)->getMessageByUid($uid);
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

    // ── Служебное ────────────────────────────────────────────────────────

    private function addresses($attribute): array
    {
        $out = [];
        // Attribute — только ArrayAccess, не итератор: перебираем через toArray().
        foreach (($attribute ? $attribute->toArray() : []) as $a) {
            $out[] = Directory::fill(Mime::address($a->personal, $a->mail));
        }

        return $out;
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
}
