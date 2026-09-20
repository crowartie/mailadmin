<?php

namespace Tests\Unit;

use App\Services\Cloud\LocalFiles;
use PHPUnit\Framework\TestCase;

class LocalFilesTokensTest extends TestCase
{
    private const T1 = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const T2 = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    public function test_finds_tokens_in_body_once_each(): void
    {
        $html = '<p>x</p><a href="https://files.innotec.su/' . self::T1 . '/a.png">https://files.innotec.su/' . self::T1 . '/a.png</a>'
            . '<a href="https://files.innotec.su/' . self::T2 . '">b</a>';
        $this->assertSame([self::T1, self::T2], LocalFiles::tokensIn($html, 'files.innotec.su'));
    }

    public function test_links_inside_reply_quote_are_ignored(): void
    {
        $html = '<p>Спасибо</p><div class="quote"><blockquote><p>было</p><a href="https://files.innotec.su/' . self::T1 . '/a.png">a</a>'
            . '<blockquote><a href="https://files.innotec.su/' . self::T2 . '/b">b</a></blockquote></blockquote></div>';
        $this->assertSame([], LocalFiles::tokensIn($html, 'files.innotec.su'));
    }

    public function test_forwarded_links_outside_quote_are_kept(): void
    {
        $html = '<div class="fwd">---------- Пересланное письмо ----------</div><a href="https://files.innotec.su/' . self::T1 . '/a.png">a</a>'
            . '<blockquote><a href="https://files.innotec.su/' . self::T2 . '/b">b</a></blockquote>';
        $this->assertSame([self::T1], LocalFiles::tokensIn($html, 'files.innotec.su'));
    }

    public function test_other_hosts_and_short_tokens_are_ignored(): void
    {
        $html = '<a href="https://cloud.example.ru/' . self::T1 . '">x</a><a href="https://files.innotec.su/short">y</a>';
        $this->assertSame([], LocalFiles::tokensIn($html, 'files.innotec.su'));
        $this->assertSame([], LocalFiles::tokensIn(null, 'files.innotec.su'));
        $this->assertSame([], LocalFiles::tokensIn($html, ''));
    }
}
