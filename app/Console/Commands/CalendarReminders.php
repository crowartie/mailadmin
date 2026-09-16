<?php

namespace App\Console\Commands;

use App\Models\Vmail\Mailbox;
use App\Services\Dav\DavStore;
use App\Services\Mail\ImapSession;
use App\Support\Area;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Напоминания о событиях по почте: раз в минуту смотрим, у кого сработал VALARM, и шлём письмо владельцу календаря.
 * Телефоны напоминают сами по тому же VALARM; веб-почта показывает уведомление через опрос /mail/api/status.
 */
class CalendarReminders extends Command
{
    protected $signature = 'calendar:reminders {--user=}';

    protected $description = 'Напоминания о событиях календаря по почте';

    public function handle(DavStore $store): int
    {
        $now = now();
        $users = $this->option('user') ? [strtolower($this->option('user'))] : Mailbox::query()->people()->pluck('username')->map('strtolower')->all();
        $sent = 0;
        foreach ($users as $user) {
            try {
                $events = $store->events($user, $now->copy()->subMinutes(2), $now->copy()->addDays(3));
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($events as $e) {
                if (! isset($e['alarm']) || $e['alarm'] === null || empty($e['start'])) {
                    continue;
                }
                $start = \Carbon\Carbon::parse($e['start']);
                $trigger = $start->copy()->subMinutes((int) $e['alarm']);
                // Окно в две минуты назад: планировщик мог опоздать; повторно не шлём (таблица calendar_reminders).
                if (! $trigger->between($now->copy()->subMinutes(2), $now)) {
                    continue;
                }
                $key = ($e['uid'] ?? $e['id']) . '@' . $e['start'];
                $dup = DB::table('calendar_reminders')->where('user', $user)->where('key', $key)->exists();
                if ($dup) {
                    continue;
                }
                DB::table('calendar_reminders')->insert(['user' => $user, 'key' => $key, 'sent_at' => $now]);
                $this->send($user, $e, $start);
                $sent++;
            }
        }
        DB::table('calendar_reminders')->where('sent_at', '<', $now->copy()->subDays(7))->delete();
        $this->info("Напоминаний отправлено: {$sent}");

        return self::SUCCESS;
    }

    private function send(string $user, array $e, \Carbon\Carbon $start): void
    {
        $domain = config('areas.default_domain');
        $when = ($e['allDay'] ?? false) ? $start->translatedFormat('j F') . ', весь день' : $start->timezone(config('app.timezone'))->format('d.m.Y H:i');
        $lines = [$e['title'] ?: '(без названия)', 'Когда: ' . $when];
        if (! empty($e['location'])) {
            $lines[] = 'Где: ' . $e['location'];
        }
        if (! empty($e['description'])) {
            $lines[] = '';
            $lines[] = mb_substr((string) $e['description'], 0, 1000);
        }
        $lines[] = '';
        $lines[] = 'Календарь: ' . Area::mailBase() . '/calendar';
        try {
            (new Mailer(ImapSession::smtpLocal()))->send((new Email())
                ->from(new Address('noreply@' . $domain, 'Календарь ' . $domain))->to(new Address($user))
                ->subject('Напоминание: ' . ($e['title'] ?: 'событие') . ' — ' . $when)
                ->text(implode("\n", $lines)));
        } catch (\Throwable $ex) {
            $this->error("{$user}: " . $ex->getMessage());
        }
    }
}
