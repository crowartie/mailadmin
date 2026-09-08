<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\Vmail\Mailbox;
use App\Services\Mail\ImapSession;
use App\Services\Server\Quarantine;
use App\Support\Area;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Сводка карантина сотрудникам: раз в сутки письмо «за сутки задержано N писем» со ссылками «Доставить».
 * Ссылки подписанные и действуют 7 дней — вход не нужен, чужой адрес по ссылке не выпустить.
 */
class QuarantineDigest extends Command
{
    protected $signature = 'quarantine:digest {--user= : только этому ящику} {--hours=24 : за сколько часов}';

    protected $description = 'Сводка карантина сотрудникам';

    public function handle(Quarantine $quarantine): int
    {
        $settings = AppSetting::group('quarantine');
        if (! ($settings['digest'] ?? true) && ! $this->option('user')) {
            $this->info('Сводка выключена в настройках');

            return self::SUCCESS;
        }
        $since = time() - ((int) $this->option('hours')) * 3600;
        $db = DB::connection('amavisd');
        $rows = $db->table('msgs')->join('msgrcpt', 'msgrcpt.mail_id', '=', 'msgs.mail_id')->join('maddr', 'maddr.id', '=', 'msgrcpt.rid')
            ->whereIn('msgs.quar_type', ['Q', 'F', 'Z'])->where('msgs.time_num', '>=', $since)->where('msgrcpt.rs', '!=', 'R')
            ->when($this->option('user'), fn ($q) => $q->where('maddr.email', strtolower($this->option('user'))))
            ->orderByDesc('msgs.time_num')
            ->get(['msgs.mail_id', 'msgs.secret_id', 'msgs.time_num', 'msgs.from_addr', 'msgs.subject', 'msgs.spam_level', 'msgs.content', 'maddr.email as rcpt']);

        $local = Mailbox::query()->where('active', 1)->pluck('username')->map('strtolower')->flip();
        $byUser = [];
        foreach ($rows as $r) {
            $rcpt = strtolower((string) $r->rcpt);
            if (! isset($local[$rcpt])) {
                continue;
            }
            $byUser[$rcpt][] = $r;
        }
        $sent = 0;
        foreach ($byUser as $user => $items) {
            $this->send($user, $items);
            $sent++;
        }
        $this->info("Сводок отправлено: {$sent}");

        return self::SUCCESS;
    }

    private function send(string $user, array $items): void
    {
        $domain = config('areas.default_domain');
        $base = Area::mailBase();
        $lines = [];
        $html = '';
        foreach (array_slice($items, 0, 50) as $r) {
            $subject = trim((string) (@iconv_mime_decode((string) $r->subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $r->subject)) ?: '(без темы)';
            $from = (string) $r->from_addr;
            $when = date('d.m H:i', (int) $r->time_num);
            $kind = match ($r->content) { 'V' => 'вирус', 'B' => 'вложение', default => 'спам ' . round((float) $r->spam_level, 1) };
            // Подпись относительная: письмо ведёт на хост веб-почты, а не на адрес админки из APP_URL.
            $link = $base . URL::temporarySignedRoute('mail.quarantine.release', now()->addDays(7), ['id' => $r->mail_id, 'secret' => $r->secret_id], false);
            $lines[] = "{$when}  {$from}\n   {$subject}  [{$kind}]\n   Доставить: {$link}";
            $html .= '<tr><td style="padding:6px 8px;color:#666;white-space:nowrap">' . $when . '</td><td style="padding:6px 8px"><div>' . htmlspecialchars($subject) . '</div><div style="color:#666;font-size:12px">' . htmlspecialchars($from) . ' · ' . $kind . '</div></td><td style="padding:6px 8px;white-space:nowrap"><a href="' . htmlspecialchars($link) . '" style="color:#1a56db">Доставить</a></td></tr>';
        }
        $n = count($items);
        $title = 'В карантине ' . $n . ' ' . $this->plural($n, 'письмо', 'письма', 'писем') . ' за сутки';
        $text = "Здравствуйте!\n\nПочтовый сервер задержал письма, похожие на спам. Если среди них есть нужное — нажмите «Доставить», оно придёт во «Входящие».\n\n" . implode("\n\n", $lines)
            . "\n\nВесь карантин: {$base}/mail/quarantine\nНастройки сводки — у администратора.";
        $htmlBody = '<div style="font-family:sans-serif;font-size:14px;max-width:720px"><p>Здравствуйте!</p><p>Почтовый сервер задержал письма, похожие на спам. Если среди них есть нужное — нажмите «Доставить», оно придёт во «Входящие».</p>'
            . '<table style="border-collapse:collapse;width:100%">' . $html . '</table>'
            . ($n > 50 ? '<p style="color:#666">Показаны первые 50 из ' . $n . '.</p>' : '')
            . '<p><a href="' . htmlspecialchars($base) . '/mail/quarantine" style="color:#1a56db">Весь карантин в веб-почте</a></p></div>';
        try {
            (new Mailer(ImapSession::smtpLocal()))->send((new Email())
                ->from(new Address('noreply@' . $domain, 'Почта ' . $domain))->to(new Address($user))
                ->subject($title)->text($text)->html($htmlBody));
        } catch (\Throwable $e) {
            $this->error("{$user}: " . $e->getMessage());
        }
    }

    private function plural(int $n, string $a, string $b, string $c): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $c;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $b;
        }
        if ($n1 === 1) {
            return $a;
        }

        return $c;
    }
}
