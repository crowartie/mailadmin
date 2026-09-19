<?php

namespace Tests\Unit;

use App\Services\Mail\Structure;
use PHPUnit\Framework\TestCase;

/**
 * Из каких частей складывается текст письма.
 *
 * Написано по настоящему письму из Apple Mail: текст был разбит на три куска,
 * между которыми лежали два PDF, и первый кусок состоял из пустой строки.
 * Бралась только первая часть — письмо показывалось пустым, хотя весь текст
 * лежал в последнем куске.
 */
class StructureBodyTest extends TestCase
{
    /** @return array<string,mixed> */
    private function part(string $no, string $mime, string $name = '', ?string $disposition = null): array
    {
        return [
            'no' => $no,
            'mime' => $mime,
            'type' => explode('/', $mime)[0],
            'subtype' => explode('/', $mime)[1] ?? '',
            'name' => $name,
            'id' => '',
            'disposition' => $disposition,
            'size' => 100,
            'charset' => 'utf-8',
        ];
    }

    /** Простое письмо: текст и разметка — два разных представления одного письма. */
    public function test_plain_and_html_are_both_taken(): void
    {
        $parts = [$this->part('1', 'text/plain'), $this->part('2', 'text/html')];

        $this->assertSame(['1', '2'], array_column(Structure::bodyParts($parts), 'no'));
    }

    /** Текст вперемежку с вложениями: нужны все куски, по порядку. */
    public function test_text_split_around_attachments_is_taken_whole(): void
    {
        $parts = [
            $this->part('1', 'text/plain'),
            $this->part('2', 'application/pdf', 'счёт.pdf', 'attachment'),
            $this->part('3', 'text/plain'),
            $this->part('4', 'application/pdf', 'акт.pdf', 'attachment'),
            $this->part('5', 'text/plain'),
        ];

        $this->assertSame(['1', '3', '5'], array_column(Structure::bodyParts($parts), 'no'));
    }

    /** Приложенный .txt — вложение, а не продолжение письма. */
    public function test_attached_text_file_is_not_part_of_the_body(): void
    {
        $parts = [
            $this->part('1', 'text/plain'),
            $this->part('2', 'text/plain', 'записка.txt', 'attachment'),
            $this->part('3', 'text/plain', 'без-расположения.txt'),
        ];

        $this->assertSame(['1'], array_column(Structure::bodyParts($parts), 'no'));
    }

    /**
     * Вложенное пересланное письмо лежит уровнем ниже — его текст в тело не попадает,
     * иначе письмо показывалось бы дважды.
     */
    public function test_nested_message_is_not_glued_to_the_body(): void
    {
        $parts = [
            $this->part('1', 'text/plain'),
            $this->part('2.1', 'text/plain'),
            $this->part('2.2', 'text/html'),
        ];

        $this->assertSame(['1'], array_column(Structure::bodyParts($parts), 'no'));
    }

    /** Части одного уровня внутри вложенной структуры тоже склеиваются. */
    public function test_parts_inside_one_level_are_glued(): void
    {
        $parts = [
            $this->part('1.1', 'text/plain'),
            $this->part('1.2', 'application/pdf', 'а.pdf', 'attachment'),
            $this->part('1.3', 'text/plain'),
            $this->part('2', 'application/pdf', 'б.pdf', 'attachment'),
        ];

        $this->assertSame(['1.1', '1.3'], array_column(Structure::bodyParts($parts), 'no'));
    }

    public function test_letter_without_text_gives_nothing(): void
    {
        $this->assertSame([], Structure::bodyParts([$this->part('1', 'application/pdf', 'счёт.pdf', 'attachment')]));
        $this->assertSame([], Structure::bodyParts([]));
    }
}
