<?php

namespace App\Services\Mail;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

/**
 * Чтение письма — одной точкой входа.
 *
 * Работа разложена по частям, каждая отвечает за своё:
 *   MessageFetch    — достать письмо по UID, не падая;
 *   MessageBody     — тело, заголовки, исходник;
 *   ThreadBuilder   — переписка;
 *   MailAttachments — вложения, архив, предпросмотр.
 *
 * Снаружи ничего не изменилось: MailStore зовёт те же методы, что и раньше.
 */
class MessageReader
{
    /**
     * Сколько писем переписки осталось за пределами показанного.
     *
     * Контроллер читает это сразу после threadOf, поэтому здесь значение переносится
     * из части — так внешний вызов остался ровно таким, каким был.
     */
    public int $threadHidden = 0;

    private readonly MessageFetch $fetch;

    private readonly MessageBody $body;

    private readonly ThreadBuilder $threads;

    private readonly MailAttachments $files;

    public function __construct(
        Client $client,
        FolderTree $tree,
        MessageSummary $summaries,
        MailActions $actions,
    ) {
        $this->fetch = new MessageFetch($tree);
        $this->body = new MessageBody($client, $tree, $summaries, $actions, $this->fetch);
        $this->threads = new ThreadBuilder($client, $tree, $summaries);
        $this->files = new MailAttachments($client, $tree, $this->fetch);
    }

    public function message(string $path, int $uid, bool $markSeen = true): array
    {
        return $this->body->message($path, $uid, $markSeen);
    }

    /** Цепочка ответов для уже открытого письма. */
    public function threadOf(string $path, int $uid): array
    {
        $out = $this->threads->threadOf($path, $uid);
        $this->threadHidden = $this->threads->threadHidden;

        return $out;
    }

    /** Полное письмо: тело, адреса, вложения. */
    public function full(Message $message, string $path): array
    {
        return $this->body->full($message, $path);
    }

    /**
     * Одно вложение письма по его номеру.
     *
     * Сначала пробуем взять его одной частью: содержимое каждой картинки из текста
     * письма браузер запрашивает отдельно, и выкачивать ради неё письмо целиком (а с ним
     * и все прочие вложения) — это секунды и десятки мегабайт памяти на каждый запрос.
     */
    public function attachedMessage(string $path, int $uid, int $index): array
    {
        return $this->files->attachedMessage($path, $uid, $index);
    }

    public function attachedPart(string $path, int $uid, int $index, int $sub): MailPart
    {
        return $this->files->attachedPart($path, $uid, $index, $sub);
    }

    public function attachment(string $path, int $uid, int $index): MailPart
    {
        return $this->files->attachment($path, $uid, $index);
    }

    /** @var Attachment $a */
    public function attachmentsZip(string $path, int $uid): array
    {
        return $this->files->attachmentsZip($path, $uid);
    }

    /**
     * Предпросмотр офисного вложения: LibreOffice (headless) переводит документ в PDF, результат кэшируется по
     * содержимому файла (storage/app/private/preview, чистится раз в сутки старше недели). Преобразования идут
     * по одному — процессор слабый, а конвертер прожорливый. Возвращает путь к PDF.
     */
    public function attachmentPreviewPdf(string $path, int $uid, int $index): string
    {
        return $this->files->attachmentPreviewPdf($path, $uid, $index);
    }

    public function headersText(string $path, int $uid): string
    {
        return $this->body->headersText($path, $uid);
    }

    public function raw(string $path, int $uid): string
    {
        return $this->body->raw($path, $uid);
    }

    /** Сырые заголовки писем по UID. @return array<int,string> */
    public function rawHeaders(string $path, array $uids): array
    {
        return $this->body->rawHeaders($path, $uids);
    }
}
