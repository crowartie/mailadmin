<?php

namespace Tests\Unit;

use App\Exceptions\MailException;
use App\Services\Mail\Outgoing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

class OutgoingSmtpErrorTest extends TestCase
{
    public function test_unknown_recipient_names_the_address(): void
    {
        $e = new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <kovyazinsa@innotec.su>: Recipient address rejected: User unknown".', 550);
        $x = Outgoing::smtpFailure($e);
        $this->assertInstanceOf(MailException::class, $x);
        $this->assertSame(422, $x->status());
        $this->assertStringContainsString('kovyazinsa@innotec.su', $x->getMessage());
        $this->assertStringContainsString('не существует', $x->getMessage());
    }

    public function test_temporary_failure_says_try_later(): void
    {
        $e = new UnexpectedResponseException('Expected response code "250/251/252" but got code "451", with message "451 4.3.0 Temporary lookup failure".', 451);
        $this->assertStringContainsString('Попробуйте через несколько минут', Outgoing::smtpFailure($e)->getMessage());
    }

    public function test_size_limit(): void
    {
        $e = new UnexpectedResponseException('Expected response code "250/251/252" but got code "552", with message "552 5.3.4 Message size exceeds fixed limit".', 552);
        $this->assertStringContainsString('слишком большое', Outgoing::smtpFailure($e)->getMessage());
    }

    public function test_connection_failure_is_upstream(): void
    {
        $e = new TransportException('Connection could not be established with host "127.0.0.1:587": Connection refused');
        $m = Outgoing::smtpFailure($e)->getMessage();
        $this->assertStringContainsString('Не удалось связаться с почтовым сервером', $m);
        $this->assertStringContainsString('Connection refused', $m);
    }
}
