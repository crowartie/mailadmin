<?php

namespace App\Dav;

use App\Services\Mail\ImapSession;
use Illuminate\Support\Facades\Log;
use Sabre\CalDAV\Schedule\IMipPlugin;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Приглашения на встречи внешним участникам (iTIP по почте, RFC 6047).
 * Штатный плагин sabre шлёт через mail(); здесь письмо уходит через локальный Postfix
 * от имени организатора, чтобы получатель мог ответить ему напрямую и письмо было подписано DKIM.
 */
class IMip extends IMipPlugin
{
    protected function mail($to, $subject, $body, array $headers)
    {
        $from = $this->senderEmail;
        $replyTo = null;
        $method = 'REQUEST';
        foreach ($headers as $h) {
            if (stripos($h, 'Reply-To:') === 0) {
                $replyTo = trim(substr($h, 9));
            }
            if (preg_match('/method=([A-Z]+)/i', $h, $m)) {
                $method = strtoupper($m[1]);
            }
        }
        $domains = array_map('strtolower', (array) config('areas.local_domains', [config('areas.default_domain')]));
        // Организатор с нашего домена — письмо от него; иначе от служебного адреса.
        if ($replyTo && in_array(strtolower(explode('@', $replyTo)[1] ?? ''), $domains, true)) {
            $from = $replyTo;
        }

        try {
            $email = (new Email())
                ->from(new Address($from))
                ->to(new Address($to))
                ->subject($subject)
                ->text("Приглашение на встречу во вложении.\n\n" . $subject)
                ->attach($body, 'invite.ics', 'text/calendar; charset=utf-8; method=' . $method);
            if ($replyTo) {
                $email->replyTo(new Address($replyTo));
            }
            // Основная часть — сам iCalendar: так его понимают Outlook и Gmail без вложения.
            $email->html('<p>Приглашение на встречу: ' . htmlspecialchars($subject) . '</p>');
            (new Mailer(ImapSession::smtpLocal()))->send($email);
        } catch (\Throwable $e) {
            Log::warning('iMIP: письмо не отправлено', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }
}
