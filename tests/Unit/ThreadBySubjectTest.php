<?php

namespace Tests\Unit;

use App\Services\Mail\ThreadBuilder;
use PHPUnit\Framework\TestCase;

/** Запасная склейка переписки по теме: только письмо и ответы на него, не одинаковые уведомления. */
class ThreadBySubjectTest extends TestCase
{
    public function test_reply_joins_original(): void
    {
        $this->assertTrue(ThreadBuilder::sameConversationBySubject('Счёт на оплату 15', 'Re: Счёт на оплату 15'));
        $this->assertTrue(ThreadBuilder::sameConversationBySubject('RE: Fwd: Договор поставки', 'Fwd: Договор поставки'));
        $this->assertTrue(ThreadBuilder::sameConversationBySubject('Ответ: Договор поставки', 'Договор поставки'));
    }

    public function test_same_subject_originals_are_separate(): void
    {
        // 26.09.2026: одиннадцать уведомлений «Вход в почту с нового устройства» собрались в одну цепочку.
        $this->assertFalse(ThreadBuilder::sameConversationBySubject('Вход в почту с нового устройства', 'Вход в почту с нового устройства'));
        $this->assertFalse(ThreadBuilder::sameConversationBySubject('Ежедневный отчёт склада', 'Ежедневный отчёт склада'));
    }

    public function test_other_subject_or_too_short(): void
    {
        $this->assertFalse(ThreadBuilder::sameConversationBySubject('Re: Счёт на оплату 15', 'Счёт на оплату 16'));
        $this->assertFalse(ThreadBuilder::sameConversationBySubject('Re: Счёт', 'Счёт'));
    }

    public function test_automatic_senders(): void
    {
        $this->assertTrue(ThreadBuilder::automaticSender('noreply@innotec.su'));
        $this->assertTrue(ThreadBuilder::automaticSender('no-reply@example.com'));
        $this->assertTrue(ThreadBuilder::automaticSender('MAILER-DAEMON@mail.innotec.su'));
        $this->assertFalse(ThreadBuilder::automaticSender('RymarevEN@innotec.su'));
    }
}
