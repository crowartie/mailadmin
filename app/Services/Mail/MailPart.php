<?php

namespace App\Services\Mail;

use Webklex\PHPIMAP\Attachment;

/**
 * Одно вложение письма — имя, тип и содержимое.
 *
 * Появился, чтобы вложение можно было отдать, не скачивая всё письмо: содержимое
 * берётся одной частью по её номеру (см. Structure). Раньше на каждую картинку из
 * текста письма сервер выкачивал письмо целиком и раскодировал все вложения разом.
 */
final class MailPart
{
    public function __construct(
        private readonly string $name,
        private readonly string $mime,
        private readonly string $content,
    ) {
    }

    /** Обернуть вложение библиотеки — для запасного пути, где структура не разобралась. */
    public static function fromAttachment(Attachment $a, string $fallback = 'attachment'): self
    {
        return new self(Mime::attachmentName($a, $fallback), $a->getMimeType() ?: 'application/octet-stream', (string) $a->getContent());
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMimeType(): string
    {
        return $this->mime;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getSize(): int
    {
        return strlen($this->content);
    }
}
