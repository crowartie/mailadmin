<?php

namespace App\Console\Commands;

use App\Models\Webmail\Reminder;
use App\Models\Webmail\Snooze;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use Illuminate\Console\Command;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Раз в минуту: вернуть отложенные письма во «Входящие» и положить напоминания
 * о письмах, на которые не ответили. Работает через master-пользователя Dovecot.
 */
class MailWake extends Command
{
    protected $signature = 'mail:wake';

    protected $description = 'Вернуть отложенные письма и разослать напоминания «нет ответа»';

    public function handle(): int
    {
        $due = Snooze::where('until', '<=', now())->get()->groupBy('user');
        foreach ($due as $user => $rows) {
            $this->withStore($user, function (MailStore $store) use ($rows) {
                $snoozed = $store->rolePath('snoozed');
                foreach ($rows as $row) {
                    $uid = $store->findByMessageId($snoozed, $row->message_id);
                    if ($uid) {
                        $store->move($snoozed, [$uid], $store->rolePath('inbox'));
                        $back = $store->findByMessageId($store->rolePath('inbox'), $row->message_id);
                        if ($back) {
                            $store->flag($store->rolePath('inbox'), [$back], '\\Seen', false);
                            $store->flag($store->rolePath('inbox'), [$back], '\\Flagged', true);
                        }
                        $this->line("{$row->user}: вернул «{$row->subject}»");
                    }
                    $row->delete();
                }
            });
        }

        $reminders = Reminder::where('remind_at', '<=', now())->get()->groupBy('user');
        foreach ($reminders as $user => $rows) {
            $this->withStore($user, function (MailStore $store) use ($rows, $user) {
                $inbox = $store->rolePath('inbox');
                foreach ($rows as $row) {
                    if (! $store->hasReplyTo($inbox, $row->message_id)) {
                        $email = (new Email())
                            ->from(new Address('noreply@' . (explode('@', $user)[1] ?? config('areas.default_domain')), 'Почта'))
                            ->to(new Address($user))
                            ->subject('Напоминание: нет ответа на «' . ($row->subject ?: 'без темы') . '»')
                            ->text("Вы просили напомнить, если на письмо «{$row->subject}» (кому: {$row->to}) не ответят.\nОтвета пока нет.")
                            ->html('<p>Вы просили напомнить, если на письмо <b>' . e($row->subject) . '</b> (кому: ' . e((string) $row->to) . ') не ответят.</p><p>Ответа пока нет.</p>');
                        $email->getHeaders()->addIdHeader('Message-ID', $email->generateMessageId());
                        $email->getHeaders()->addIdHeader('In-Reply-To', $row->message_id);
                        $store->append($inbox, $email->toString(), ['\\Flagged']);
                        $this->line("{$user}: напоминание «{$row->subject}»");
                    }
                    $row->delete();
                }
            });
        }

        return self::SUCCESS;
    }

    private function withStore(string $user, callable $fn): void
    {
        try {
            $client = ImapSession::master($user);
            $fn(new MailStore($client));
            $client->disconnect();
        } catch (\Throwable $e) {
            $this->error("{$user}: {$e->getMessage()}");
        }
    }
}
