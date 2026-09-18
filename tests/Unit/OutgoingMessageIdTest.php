<?php

namespace Tests\Unit;

use App\Services\Mail\Outgoing;
use PHPUnit\Framework\TestCase;

class OutgoingMessageIdTest extends TestCase
{
    public function test_reads_message_id_from_raw_mail(): void
    {
        $raw = "Subject: test\r\nMessage-ID: <abc123@mail.innotec.su>\r\nFrom: a@b.c\r\n\r\nтело";
        $this->assertSame('abc123@mail.innotec.su', Outgoing::rawMessageId($raw));
    }

    public function test_reads_message_id_with_unix_line_endings(): void
    {
        $raw = "Subject: test\nMessage-ID: <x@y>\nFrom: a@b.c\n\nтело";
        $this->assertSame('x@y', Outgoing::rawMessageId($raw));
    }

    /** Строка из тела письма не должна сойти за заголовок: копия легла бы не туда. */
    public function test_ignores_message_id_inside_body(): void
    {
        $raw = "Subject: test\r\nFrom: a@b.c\r\n\r\nMessage-ID: <fake@body>";
        $this->assertNull(Outgoing::rawMessageId($raw));
    }

    public function test_returns_null_when_header_absent(): void
    {
        $this->assertNull(Outgoing::rawMessageId("Subject: test\r\n\r\nтело"));
    }
}
