<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\FeedbackTicket;
use App\Services\Mail\ImapSession;
use App\Services\Server\Alerts;
use App\Support\Area;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Письма по обращениям. Смысл один: сотрудник, написавший о проблеме, должен узнать ответ,
 * даже если он в этот момент не в веб-почте — иначе в следующий раз он просто не напишет.
 * Администраторам уходит обычное оповещение сервера (почта и Telegram из настроек).
 */
class FeedbackNotifier
{
    /** Сотруднику: администратор ответил или закрыл обращение. */
    public static function toUser(FeedbackTicket $ticket, string $body): void
    {
        $subject = 'Обращение №' . $ticket->id . ($ticket->subject !== '' ? ': ' . mb_substr($ticket->subject, 0, 120) : '');
        $link = rtrim(Area::mailBase(), '/') . '/mail/feedback?id=' . $ticket->id;
        $domain = config('areas.default_domain');
        $from = self::address();
        // Ответ на это письмо feedback:import подшивает в обращение (по Message-ID <feedback-N-…>), если ящик есть.
        $text = ($from === self::mailbox()
                ? "Ответить можно прямо на это письмо — ответ попадёт в обращение. Или в веб-почте: {$link}"
                : "Ответить можно в веб-почте: {$link}")
            . "\n\n" . $body;
        try {
            $email = (new Email())
                ->from(new Address($from, 'Обращения · ' . $domain))
                ->to(new Address($ticket->user))
                ->subject($subject)
                ->text($text);
            $email->getHeaders()->addIdHeader('Message-ID', 'feedback-' . $ticket->id . '-' . bin2hex(random_bytes(6)) . '@' . $domain);
            (new Mailer(ImapSession::smtpLocal()))->send($email);
        } catch (\Throwable $e) {
            Log::warning('Ответ по обращению не отправлен', ['ticket' => $ticket->id, 'error' => $e->getMessage()]);
        }
    }

    /** Администраторам: пришло новое обращение (по каналам из Настройки → Уведомления). */
    public static function toAdmins(FeedbackTicket $ticket, string $text): void
    {
        if (! (AppSetting::group('alerts')['feedback'] ?? true)) {
            return;
        }
        $who = $ticket->user_name !== '' ? $ticket->user_name . ' (' . $ticket->user . ')' : $ticket->user;
        $body = "Обращение №{$ticket->id} от {$who}\n"
            . 'Тип: ' . (FeedbackTicket::KINDS[$ticket->kind] ?? $ticket->kind)
            . ($ticket->priority === 'high' ? ', срочно' : '') . "\n"
            . ($ticket->page_title !== '' ? "Страница: {$ticket->page_title}\n" : '')
            . ($ticket->client !== '' ? "Программа: {$ticket->client}\n" : '')
            . "\n" . mb_substr($text, 0, 1500)
            . "\n\nОткрыть: " . rtrim(self::adminBase(), '/') . '/feedback?id=' . $ticket->id;
        try {
            app(Alerts::class)->send($body);
        } catch (\Throwable $e) {
            Log::warning('Оповещение о новом обращении не отправлено', ['ticket' => $ticket->id, 'error' => $e->getMessage()]);
        }
    }

    /** Служебный ящик обращений: сюда приходят ответы письмом. */
    public static function mailbox(): string
    {
        return 'feedback@' . config('areas.default_domain');
    }

    /** Адрес отправителя уведомлений: ящик обращений, пока его нет — noreply. */
    public static function address(): string
    {
        $mailbox = self::mailbox();
        try {
            if (\App\Models\Vmail\Mailbox::query()->where('username', $mailbox)->where('active', 1)->exists()) {
                return $mailbox;
            }
        } catch (\Throwable) {
            // схема vmail недоступна
        }

        return 'noreply@' . config('areas.default_domain');
    }

    /**
     * Служебный ящик обращений создаётся сам при первом запуске feedback:import: пароль случайный
     * (в него никто не входит, читает планировщик через master-пользователя), в списке — как служебный.
     */
    public static function ensureMailbox(): ?string
    {
        $mailbox = self::mailbox();
        try {
            if (\App\Models\Vmail\Mailbox::query()->where('username', $mailbox)->exists()) {
                return $mailbox;
            }
            [$local, $domain] = explode('@', $mailbox, 2);
            app(\App\Services\Vmail\MailboxService::class)->create([
                'local_part' => $local, 'domain' => $domain, 'password' => bin2hex(random_bytes(16)),
                'name' => 'Обращения', 'quota' => 512, 'active' => true,
            ]);
            \App\Models\EmployeeProfile::query()->updateOrCreate(['username' => $mailbox], ['is_service' => true]);
            Log::info('Создан служебный ящик обращений', ['mailbox' => $mailbox]);

            return $mailbox;
        } catch (\Throwable $e) {
            Log::warning('Служебный ящик обращений не создан', ['mailbox' => $mailbox, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Базовый адрес админки для ссылок из писем (аналог Area::mailBase). */
    public static function adminBase(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'mail.' . config('areas.default_domain');
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $port = (int) config('areas.admin_port');
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $host . ($port === $default ? '' : ':' . $port);
    }
}
