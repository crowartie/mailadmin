<?php

namespace Tests\Unit;

use App\Services\Mail\MailHtml;
use Tests\TestCase;

/**
 * Тело письма — чужой HTML. Здесь проверяется то, от чего зависит безопасность
 * (скрипты, формы) и приватность (следящие пиксели): раньше внешние ссылки вырезались
 * совсем, и обещанная кнопка «Показать картинки» ничего показать не могла.
 */
class MailHtmlTest extends TestCase
{
    public function test_режет_скрипты_и_формы(): void
    {
        $out = MailHtml::sanitize('<b>текст</b><script>alert(1)</script><form action="/x"><input name="p"></form>');

        $this->assertStringContainsString('текст', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('<form', $out);
        $this->assertStringNotContainsString('<input', $out);
    }

    public function test_убирает_обработчики_событий(): void
    {
        $out = MailHtml::sanitize('<b onclick="bad()" onerror="bad()">жирный</b>');

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringContainsString('жирный', $out);
    }

    /** Внешние ссылки прячутся — и src, и srcset, и background. */
    public function test_прячет_внешние_ссылки(): void
    {
        $html = '<img src="https://track.example/p.gif">'
            . '<img srcset="//cdn.x/a.png 1x, //cdn.x/b.png 2x">'
            . '<td background="http://x/y.png">';
        $out = MailHtml::blockRemote($html);

        $this->assertStringContainsString('data-blocked-src="https://track.example/p.gif"', $out);
        $this->assertStringContainsString('data-blocked-srcset=', $out);
        $this->assertStringContainsString('data-blocked-background=', $out);
        $this->assertStringNotContainsString(' src="https://', $out);
    }

    /** Встроенные и свои картинки прятать не нужно: они ничего не сообщают отправителю. */
    public function test_не_трогает_встроенные_и_свои(): void
    {
        $html = '<img src="data:image/png;base64,AAA"><img src="/mail/api/message/INBOX/5/attachment/0?inline=1">';

        $this->assertSame($html, MailHtml::blockRemote($html));
    }

    /** Ссылки в тексте письма — не картинки, их трогать нельзя. */
    public function test_не_трогает_ссылки(): void
    {
        $html = '<a href="https://ok.example">ссылка</a>';

        $this->assertSame($html, MailHtml::blockRemote($html));
    }

    /** «Показать картинки» возвращает адрес ровно таким, каким он был. */
    public function test_обратная_замена_восстанавливает_адрес(): void
    {
        $html = '<img src="https://track.example/p.gif?id=7&u=1"><img srcset="//cdn.x/a.png 2x">';
        $back = preg_replace('/\sdata-blocked-(src|srcset|background)=/i', ' $1=', MailHtml::blockRemote($html));

        $this->assertSame($html, $back);
    }
}
