<?php

namespace Tests\Unit;

use App\Services\Mail\Structure;
use PHPUnit\Framework\TestCase;

/**
 * Что считать вложением.
 *
 * Правило одно на три места: скрепка в списке писем, отбор «Вложения» и список
 * вложений в открытом письме. Раньше они отвечали по-разному, и это стоило двух
 * настоящих поломок: скрепка врала у каждого десятого письма, а письмо из Foxmail
 * с тремя чертежами по 400 КБ показывалось как письмо без вложений — все части
 * считались картинками из текста.
 */
class StructureFilesTest extends TestCase
{
    /** @return array<string,mixed> */
    private function part(string $mime, string $name = '', string $id = '', ?string $disposition = null, string $no = '2'): array
    {
        return [
            'no' => $no,
            'mime' => $mime,
            'type' => explode('/', $mime)[0],
            'subtype' => explode('/', $mime)[1] ?? '',
            'name' => $name,
            'id' => $id,
            'disposition' => $disposition,
            'size' => 1000,
        ];
    }

    private function body(string $mime = 'text/html'): array
    {
        return $this->part($mime, '', '', null, '1');
    }

    public function test_logo_in_the_signature_is_not_an_attachment(): void
    {
        $parts = [$this->body(), $this->part('image/png', 'logo.png', 'logo@mail', 'inline')];

        $this->assertSame([], Structure::files($parts));
        $this->assertFalse(Structure::hasFiles($parts));
    }

    /** Картинка без пометки расположения — тоже из подписи: так шлёт Foxmail. */
    public function test_image_with_content_id_and_no_disposition_is_embedded(): void
    {
        $parts = [$this->body(), $this->part('image/png', 'image001.png', '_Foxmail.1@abc')];

        $this->assertFalse(Structure::hasFiles($parts));
    }

    /**
     * Файл неизвестного вида — вложение, даже если у него есть Content-ID.
     *
     * Ровно на этом терялись чертежи: Foxmail проставляет Content-ID каждой части
     * подряд, включая приложенные файлы.
     */
    public function test_file_with_content_id_is_still_an_attachment(): void
    {
        $parts = [
            $this->body(),
            $this->part('application/octet-stream', 'Actuator.jpg', '_Foxmail.1@777'),
            $this->part('application/pdf', 'договор.pdf', '_Foxmail.1@888', null, '3'),
        ];

        $files = Structure::files($parts);
        $this->assertCount(2, $files);
        $this->assertSame(['Actuator.jpg', 'договор.pdf'], array_column($files, 'name'));
    }

    /** Картинку, помеченную вложением, отправитель хочет и показать, и дать сохранить. */
    public function test_image_marked_as_attachment_stays_an_attachment(): void
    {
        $parts = [$this->body(), $this->part('image/png', 'qr.png', 'qr.png', 'attachment')];

        $this->assertTrue(Structure::hasFiles($parts));
        $this->assertSame(['qr.png'], array_column(Structure::files($parts), 'name'));
    }

    public function test_plain_letter_has_no_attachments(): void
    {
        $this->assertFalse(Structure::hasFiles([$this->body('text/plain')]));
        $this->assertFalse(Structure::hasFiles([$this->body('text/plain'), $this->part('text/html', '', '', null, '2')]));
    }

    public function test_ordinary_attachment_without_content_id(): void
    {
        $parts = [$this->body(), $this->part('application/pdf', 'счёт.pdf', '', 'attachment')];

        $this->assertTrue(Structure::hasFiles($parts));
        $this->assertSame(['счёт.pdf'], array_column(Structure::files($parts), 'name'));
    }

    /** Заглавные буквы в типе не должны менять ответ. */
    public function test_mime_case_does_not_matter(): void
    {
        $this->assertTrue(Structure::isEmbeddedImage($this->part('IMAGE/PNG', 'l.png', 'cid1')));
        $this->assertFalse(Structure::isEmbeddedImage($this->part('IMAGE/PNG', 'l.png', 'cid1', 'attachment')));
        $this->assertFalse(Structure::isEmbeddedImage($this->part('IMAGE/PNG', 'l.png', '')));
    }
}
