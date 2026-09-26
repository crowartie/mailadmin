<?php

namespace Tests\Unit;

use App\Services\Mail\MessageBody;
use PHPUnit\Framework\TestCase;

/**
 * Письма, ушедшие до 25.09 с картинками-ссылками на наш сервер, и ответы с их цитатой: картинки
 * чужого письма не показываем (было 404 или картинки другого письма), свои — оставляем.
 */
class ForeignServerImagesTest extends TestCase
{
    public function test_чужие_убираются_свои_остаются(): void
    {
        $html = '<p>Ответ</p>'
            . '<img width="36" src="/mail/api/message/INBOX/3401/attachment/0?inline=1" alt="0">'
            . '<img src="https://mail.example.org/mail/api/message/INBOX/3401/attachment/5?inline=1">'
            . '<img src="/mail/api/message/%D0%93%D0%A0%D0%9E%D0%A1%D0%A1/52/attachment/1?inline=1" alt="своя">'
            . '<img src="https://example.org/logo.png">';
        $out = MessageBody::dropForeignServerImages($html, 'ГРОСС', 52);
        $this->assertStringNotContainsString('INBOX/3401', $out);
        $this->assertStringContainsString('/52/attachment/1', $out);
        $this->assertStringContainsString('https://example.org/logo.png', $out);
        $this->assertStringContainsString('<p>Ответ</p>', $out);
    }

    public function test_тот_же_номер_в_другой_папке_чужой(): void
    {
        $html = '<img src="/mail/api/message/Sent/52/attachment/0?inline=1">';
        $this->assertSame('', MessageBody::dropForeignServerImages($html, 'INBOX', 52));
    }

    public function test_без_ссылок_без_изменений(): void
    {
        $html = '<p>x</p><img src="data:image/png;base64,AAAA">';
        $this->assertSame($html, MessageBody::dropForeignServerImages($html, 'INBOX', 1));
    }
}
