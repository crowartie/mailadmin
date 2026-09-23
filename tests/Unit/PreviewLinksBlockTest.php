<?php

namespace Tests\Unit;

use App\Services\Mail\MessageSummary;
use PHPUnit\Framework\TestCase;

/** Превью в списке писем: блок ссылок на файлы не должен вытеснять текст письма. */
class PreviewLinksBlockTest extends TestCase
{
    private const BLOCK = 'К этому письму приложены ссылки на следующие файлы: Выписка по счёту 3.pdf (153 КБ) Ссылка для скачивания: https://files.example.ru/kBgbonSpKD4xmpdfielHbDQYfNJx-yimlqZUsYj0gnY/Выписка по счёту 3.pdf '
        . 'IMG_6597.jpeg (862 КБ) Ссылка для скачивания: https://files.example.ru/gkOf0E5wrUcA4JMdnbi-i2-poSQlCaBv1O8uYG8lyjA/IMG_6597.jpeg Ссылка защищена паролем — его сообщит отправитель. '
        . 'IMG_6598.jpeg (3 МБ) Ссылка для скачивания: https://files.exa';

    public function test_без_текста_показывает_имена_файлов(): void
    {
        $this->assertSame('Файлы: Выписка по счёту 3.pdf, IMG_6597.jpeg', MessageSummary::withoutLinksBlock(self::BLOCK));
    }

    public function test_текст_письма_до_блока_остаётся(): void
    {
        $this->assertSame('Добрый день, видео с объекта.', MessageSummary::withoutLinksBlock('Добрый день, видео с объекта. ' . self::BLOCK));
    }

    public function test_обычное_письмо_не_трогается(): void
    {
        $this->assertSame('Просто текст', MessageSummary::withoutLinksBlock('Просто текст'));
    }

    public function test_оборванный_блок_без_имён(): void
    {
        $this->assertSame('Файлы по ссылкам', MessageSummary::withoutLinksBlock('К этому письму приложены ссылки на следующие файлы: Выпи'));
    }
}
