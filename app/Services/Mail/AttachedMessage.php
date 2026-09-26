<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Webklex\PHPIMAP\Message;

/**
 * Письмо, приложенное к письму (message/rfc822, файл .eml).
 *
 * Так пересылают переписку целиком: получатель видит исходное письмо со всеми заголовками,
 * а не пересказ в цитате. Kerio это умел, и люди этого ждут (обращение №39). Здесь — разбор
 * такого вложения: шапка, тело и его собственные вложения, чтобы показать письмо в почте,
 * не заставляя человека скачивать файл и открывать его в другой программе.
 */
final class AttachedMessage
{
    /** Больше этого не разбираем: письмо с гигабайтом внутри положит воркер. */
    public const MAX_BYTES = 60 * 1024 * 1024;

    /** Похоже ли вложение на письмо: по типу или по расширению. */
    public static function looksLikeMail(string $mime, string $name): bool
    {
        return strtolower(trim($mime)) === 'message/rfc822'
            || in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['eml', 'mht'], true);
    }

    /**
     * Разобрать исходник письма в те же поля, какими веб-почта показывает обычное письмо.
     *
     * @return array<string,mixed>
     */
    public static function parse(string $raw, string $fallbackName = 'письмо.eml'): array
    {
        $raw = ltrim($raw, "\r\n");
        if ($raw === '') {
            throw MailException::notFound('Вложенное письмо пустое');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw MailException::tooLarge('Вложенное письмо слишком большое, чтобы показать его здесь — скачайте файл');
        }
        try {
            $m = Message::fromString($raw);
        } catch (\Throwable $e) {
            throw MailException::invalid('Не удалось прочитать вложенное письмо: ' . mb_substr($e->getMessage(), 0, 120));
        }

        $html = (string) ($m->getHTMLBody() ?: '');
        $text = trim((string) ($m->getTextBody() ?: ''));
        $inline = [];
        $attachments = [];
        foreach ($m->getAttachments() as $i => $a) {
            $name = Mime::attachmentName($a, 'вложение-' . ($i + 1));
            $cid = trim((string) ($a->getId() ?? ''), '<>');
            $isInline = $cid !== '' && $html !== '' && str_contains($html, $cid);
            $row = [
                'index' => (int) $i,
                'name' => $name,
                'size' => (int) ($a->getSize() ?: strlen((string) $a->getContent())),
                'type' => (string) ($a->getMimeType() ?: 'application/octet-stream'),
                'inline' => $isInline,
            ];
            $attachments[] = $row;
            // Картинку из тела показываем сразу: отдельным запросом её не достать —
            // она лежит внутри вложенного письма, а не в самом письме.
            if ($isInline && $row['size'] <= 2 * 1024 * 1024) {
                $inline['cid:' . $cid] = 'data:' . $row['type'] . ';base64,' . base64_encode((string) $a->getContent());
            }
        }
        if ($inline !== [] && $html !== '') {
            $html = strtr($html, $inline);
        }

        $header = (string) ($m->getHeader()?->raw ?? '');
        $date = null;
        try {
            $date = $m->getDate()?->first();
        } catch (\Throwable) {
        }

        return [
            'name' => $fallbackName,
            'subject' => trim((string) Charset::header((string) ($m->getSubject()->first() ?? ''))) ?: '(без темы)',
            'from' => ($f = Mime::firstAddress(Mime::headerValue($header, 'From') ?? '')) ? Directory::fill($f) : null,
            'to' => MailAddresses::of($m->getTo()),
            'cc' => MailAddresses::of($m->getCc()),
            'date' => $date ? $date->toIso8601String() : null,
            'html' => $html !== '' ? MessageBody::dropForeignServerImages(MailHtml::sanitize($html), '', 0) : null,
            'text' => $text !== '' ? $text : null,
            'attachments' => $attachments,
            'size' => strlen($raw),
        ];
    }

    /** Одно вложение изнутри приложенного письма. */
    public static function part(string $raw, int $index): MailPart
    {
        try {
            $m = Message::fromString(ltrim($raw, "\r\n"));
        } catch (\Throwable) {
            throw MailException::invalid('Не удалось прочитать вложенное письмо');
        }
        foreach ($m->getAttachments() as $i => $a) {
            if ((int) $i === $index) {
                return MailPart::fromAttachment($a, 'вложение-' . ($index + 1));
            }
        }

        throw MailException::notFound('Вложение не найдено');
    }

    /** Имя файла для письма, приложенного к письму: тема плюс .eml. */
    public static function fileName(string $subject): string
    {
        $name = trim(preg_replace('/[\\\\\\/:*?"<>|\\x00-\\x1F]+/u', ' ', $subject) ?? '');
        $name = trim(preg_replace('/\\s{2,}/u', ' ', $name) ?? $name);
        if ($name === '') {
            $name = 'письмо';
        }

        return mb_substr($name, 0, 80) . '.eml';
    }
}
