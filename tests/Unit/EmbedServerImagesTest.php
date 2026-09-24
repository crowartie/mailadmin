<?php

namespace Tests\Unit;

use App\Services\Mail\MailBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Картинки из текста исходного письма — ссылки на наш сервер. В исходящее письмо они должны уйти
 * самими картинками: раньше уходили ссылками, и у получателя картинки были битыми.
 */
class EmbedServerImagesTest extends TestCase
{
    public function test_ссылка_становится_картинкой(): void
    {
        $calls = [];
        $fetch = function (string $path, int $uid, int $i) use (&$calls) {
            $calls[] = [$path, $uid, $i];

            return ['image/png', 'PNGDATA'];
        };
        $html = '<p>x</p><img src="/mail/api/message/Shared%2Finfo%2FINBOX/182/attachment/0?inline=1"><img src=\'/mail/api/message/INBOX/7/attachment/2\'>';
        $out = MailBuilder::embedServerImages($html, $fetch);
        $this->assertSame([['Shared/info/INBOX', 182, 0], ['INBOX', 7, 2]], $calls);
        $this->assertStringNotContainsString('/mail/api/message/', $out);
        $this->assertSame(2, substr_count($out, 'data:image/png;base64,' . base64_encode('PNGDATA')));
    }

    public function test_одна_картинка_дважды_качается_один_раз(): void
    {
        $n = 0;
        $fetch = function () use (&$n) { $n++; return ['image/gif', 'G']; };
        $src = '/mail/api/message/INBOX/5/attachment/1?inline=1';
        MailBuilder::embedServerImages('<img src="' . $src . '"><img src="' . $src . '">', $fetch);
        $this->assertSame(1, $n);
    }

    public function test_письма_нет_или_не_картинка_ссылку_убираем(): void
    {
        $gone = fn () => throw new \RuntimeException('нет письма');
        $this->assertSame('<img src="">', MailBuilder::embedServerImages('<img src="/mail/api/message/INBOX/9/attachment/0?inline=1">', $gone));
        $pdf = fn () => ['application/pdf', '%PDF'];
        $this->assertSame('<img src="">', MailBuilder::embedServerImages('<img src="/mail/api/message/INBOX/9/attachment/0">', $pdf));
    }

    public function test_прочее_не_трогаем(): void
    {
        $fetch = fn () => throw new \LogicException('качать нечего');
        $html = '<a href="/mail/api/message/INBOX/1/attachment/0">файл</a><img src="https://example.com/a.png"><img src="data:image/png;base64,AA==">';
        $this->assertSame($html, MailBuilder::embedServerImages($html, $fetch));
    }
}
