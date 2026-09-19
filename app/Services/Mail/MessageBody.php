<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Чтение письма: тело, заголовки, исходник.
 *
 * Письмо открывается, не скачиваясь целиком: состав берётся из структуры (Structure),
 * а тело дочитывается отдельными частями. Старый путь — когда библиотека качает письмо
 * со всеми вложениями — остаётся запасным на случай, если структура не разобралась.
 */
class MessageBody
{
    public function __construct(
        private readonly Client $client,
        private readonly FolderTree $tree,
        private readonly MessageSummary $summaries,
        private readonly MailActions $actions,
        private readonly MessageFetch $fetch,
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
            // Встроенной — то есть скрытой из списка вложений — часть считается,
            // только если это картинка, отправитель не пометил её вложением и на неё
            // действительно ссылается разметка. Правило общее со списком писем
            // и отбором «Вложения», см. Structure::isEmbeddedImage.
            $referenced = $cid !== '' && $html !== null && str_contains($html, 'cid:' . $cid);
            $isInline = $referenced && Structure::isEmbeddedImage($a);
            if ($referenced) {
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
            'to' => MailAddresses::of($message->getTo()),
            'cc' => MailAddresses::of($message->getCc()),
            'bcc' => MailAddresses::of($message->getBcc()),
            'replyTo' => MailAddresses::of($message->getReplyTo()),
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
            'to' => MailAddresses::of($message->getTo()),
            'cc' => MailAddresses::of($message->getCc()),
            // Скрытая копия нужна, чтобы черновик открывался тем же письмом, каким его сохранили.
            'bcc' => MailAddresses::of($message->getBcc()),
            'replyTo' => MailAddresses::of($message->getReplyTo()),
            'inReplyTo' => $inReplyTo,
            'references' => $refs,
            'attachments' => $attachments,
            'listUnsubscribe' => (string) ($message->getHeader()?->get('list_unsubscribe')?->first() ?? ''),
        ];
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
        $message = $this->fetch->messageOrNull($path, $uid);
        if (! $message) {
            throw MailException::notFound('Письмо не найдено — возможно, его удалили или переложили в другой вкладке');
        }

        return (string) $message->getHeader()?->raw . "\r\n\r\n" . $message->getRawBody();
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
