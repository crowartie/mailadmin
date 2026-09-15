<?php

namespace App\Console\Commands;

use App\Models\FeedbackMessage;
use App\Models\FeedbackTicket;
use App\Services\FeedbackNotifier;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use App\Services\Server\Alerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\Message;

/**
 * Ответы на уведомления по обращениям, присланные обычным письмом (на feedback@<домен>), подшиваются
 * в переписку обращения — как если бы сотрудник написал в самом обращении. Работает по расписанию раз в минуту.
 *
 * Письмо привязывается к обращению по In-Reply-To/References нашего уведомления (<feedback-N-…@домен>)
 * или по теме «Обращение №N». Принимается только от автора обращения; автоответы и чужие письма пропускаются.
 */
class FeedbackImport extends Command
{
    protected $signature = 'feedback:import
        {--mailbox= : читать другой ящик (например, postmaster — ответы, ушедшие на старый noreply)}
        {--no-reopen : подшить, но не переоткрывать обращение и не помечать новым (для разбора старых писем)}
        {--keep : не удалять обработанные письма из ящика}';

    protected $description = 'Подшить ответы, присланные письмом, в переписку обращений';

    private const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public function handle(): int
    {
        $own = FeedbackNotifier::ensureMailbox();
        if ($own === null) {
            $this->warn('Служебный ящик для обращений не создан — ответы письмом не принимаются');

            return self::FAILURE;
        }
        $mailbox = strtolower((string) ($this->option('mailbox') ?: $own));
        $foreign = $mailbox !== $own;

        try {
            $client = ImapSession::master($mailbox);
        } catch (\Throwable $e) {
            $this->error("{$mailbox} IMAP: " . $e->getMessage());

            return self::FAILURE;
        }
        $inbox = $client->getFolder('INBOX');
        $store = new MailStore($client);
        $query = $inbox->query()->leaveUnread();
        // В своём ящике берём всё непрочитанное; в чужом (postmaster) — только письма по обращениям.
        $foreign ? $query->where('SUBJECT', 'Обращение') : $query->where('UNSEEN');
        $messages = $query->get();

        $done = [];
        $imported = 0;
        foreach ($messages as $m) {
            /** @var Message $m */
            $uid = (int) $m->getUid();
            try {
                $result = $this->import($m, $own);
            } catch (\Throwable $e) {
                Log::warning('feedback:import: письмо не разобрано', ['mailbox' => $mailbox, 'uid' => $uid, 'error' => $e->getMessage()]);
                $this->warn("uid {$uid}: " . $e->getMessage());
                continue;
            }
            $this->line("uid {$uid}: {$result}");
            if (str_starts_with($result, 'подшито')) {
                $imported++;
            }
            $done[] = $uid;
        }
        if ($done) {
            try {
                $store->flag('INBOX', $done, '\\Seen', true);
                if (! $foreign && ! $this->option('keep')) {
                    // Текст и файл уже в обращении; письмо в ящике больше не нужно.
                    $store->flag('INBOX', $done, '\\Deleted', true);
                    $client->openFolder('INBOX', true);
                    $client->getConnection()->expunge();
                }
            } catch (\Throwable $e) {
                $this->warn('Не пометил обработанные: ' . $e->getMessage());
            }
        }
        $this->info("{$mailbox}: писем {$messages->count()}, подшито {$imported}");

        return self::SUCCESS;
    }

    /** Одно письмо → сообщение в обращении. Возвращает короткий итог для журнала. */
    private function import(Message $m, string $own): string
    {
        $from = strtolower((string) ($m->getFrom()->first()->mail ?? ''));
        $subject = (string) $m->getSubject();
        if ($from === '' || $from === $own || preg_match('/^(mailer-daemon|postmaster|noreply|no-reply)@/i', $from)) {
            return 'пропущено: служебный отправитель ' . $from;
        }
        if ($this->isAutoReply($m)) {
            return 'пропущено: автоответ от ' . $from;
        }

        $id = $this->ticketId($m, $subject);
        $ticket = $id ? FeedbackTicket::find($id) : null;
        if (! $ticket) {
            $this->tellAdmins("Письмо на адрес обращений без номера обращения — от {$from}, тема «{$subject}». Оставлено в ящике {$own}.");

            return 'без обращения: от ' . $from . ' «' . $subject . '»';
        }
        if ($from !== strtolower($ticket->user)) {
            $this->tellAdmins("Письмо по обращению №{$ticket->id} не от его автора: от {$from}, тема «{$subject}». В обращение не подшито.");

            return "обращение №{$ticket->id}: отправитель {$from} — не автор, пропущено";
        }

        $text = $this->replyText($m);
        $file = $this->saveImage($m, $ticket);
        if ($text === '' && $file === null) {
            return "обращение №{$ticket->id}: пустой ответ, пропущено";
        }
        if ($text === '') {
            $text = '(снимок экрана)';
        }
        $others = [];
        foreach ($m->getAttachments() as $a) {
            $type = strtolower((string) $a->getMimeType());
            if (! in_array($type, self::IMAGE_TYPES, true) || $file === null) {
                $others[] = MailStore::attachmentName($a, 'файл');
            }
        }
        if ($file !== null) {
            array_shift($others);   // первая картинка сохранена как снимок — в списке «прочих» её нет
        }
        if ($others) {
            $text .= "\n\n(в письме были ещё вложения: " . implode(', ', array_unique($others)) . ' — они остались в почте)';
        }

        FeedbackMessage::create([
            'ticket_id' => $ticket->id,
            'author' => $from,
            'author_role' => 'user',
            'text' => mb_substr($text, 0, 5000),
            'file' => $file,
        ]);
        if (! $this->option('no-reopen')) {
            // Как при ответе в самом обращении: закрытое и «ждём ответа» снова в работе, админу — «новое».
            $ticket->forceFill([
                'status' => in_array($ticket->status, ['closed', 'waiting'], true) ? 'open' : $ticket->status,
                'resolution' => $ticket->status === 'closed' ? null : $ticket->resolution,
                'closed_at' => $ticket->status === 'closed' ? null : $ticket->closed_at,
                'new_for_admin' => true,
                'last_reply_at' => now(),
            ])->save();
        }

        return "подшито в обращение №{$ticket->id} (" . mb_strlen($text) . ' симв.' . ($file ? ', снимок' : '') . ')';
    }

    /** Номер обращения: из In-Reply-To/References нашего уведомления, иначе из темы «Обращение №N». */
    private function ticketId(Message $m, string $subject): ?int
    {
        $refs = trim((string) ($m->getHeader()->get('in_reply_to') ?? '') . ' ' . (string) ($m->getHeader()->get('references') ?? ''));
        if (preg_match('/<feedback-(\d+)-[^>]*>/', $refs, $x)) {
            return (int) $x[1];
        }
        if (preg_match('/Обращение\s*№\s*(\d+)/iu', $subject, $x)) {
            return (int) $x[1];
        }

        return null;
    }

    private function isAutoReply(Message $m): bool
    {
        $h = $m->getHeader();
        $auto = strtolower(trim((string) ($h->get('auto_submitted') ?? '')));
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        if ((string) ($h->get('x_auto_response_suppress') ?? '') !== '' || (string) ($h->get('x_autoreply') ?? '') !== '') {
            return true;
        }

        return (bool) preg_match('/^(bulk|junk|auto[_-]?reply|list)$/i', trim((string) ($h->get('precedence') ?? '')));
    }

    /** Текст ответа без процитированного письма и без нашей же подписи уведомления. */
    private function replyText(Message $m): string
    {
        $text = trim((string) $m->getTextBody());
        if ($text === '') {
            $html = (string) $m->getHTMLBody();
            // Цитата в HTML — blockquote или наш блок .quote; всё после него — старое письмо.
            $html = preg_replace('/<(blockquote|div class="quote")[\s\S]*$/iu', '', $html) ?? $html;
            $html = preg_replace('/<br\s*\/?>|<\/(p|div|li|tr|h\d)>/i', "\n", $html) ?? $html;
            $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return self::stripQuote($text);
    }

    /** Обрезать цитату: строки с «>», «… писал(а):», «-----Original Message-----», «От: …» и наш текст уведомления. */
    public static function stripQuote(string $text): string
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $t = trim($line);
            if ($t !== '' && $t[0] === '>') {
                break;
            }
            if (preg_match('/^(-{2,}\s*(Original Message|Исходное сообщение|Пересланное (сообщение|письмо))|On .+ wrote:?|.+\bwrote:$|.+писал\(а\):?$|.+написал\(а\)?:?$|От:\s+.+|From:\s+.+|Это автоматическое уведомление|Ответить можно прямо на это письмо|Переписка по обращению:)/iu', $t)) {
                break;
            }
            if (preg_match('/^\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}.*[<@].*:$/u', $t)) {   // «15.09.2026 12:00, Имя <a@b>:»
                break;
            }
            $out[] = rtrim($line);
        }
        // Подпись «-- » и всё после неё — не часть ответа.
        $joined = trim(implode("\n", $out));
        $joined = preg_replace('/\n-- ?\n[\s\S]*$/u', '', $joined) ?? $joined;

        return trim($joined);
    }

    /** Первая картинка из письма — как снимок экрана в обращении (те же ограничения, что в форме: до 8 МБ). */
    private function saveImage(Message $m, FeedbackTicket $ticket): ?string
    {
        foreach ($m->getAttachments() as $a) {
            $type = strtolower((string) $a->getMimeType());
            if (! in_array($type, self::IMAGE_TYPES, true)) {
                continue;
            }
            $content = (string) $a->getContent();
            if ($content === '' || strlen($content) > 8 * 1024 * 1024) {
                continue;
            }
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$type];
            $name = Str::random(16) . '.' . $ext;
            Storage::disk('local')->put('feedback/' . $ticket->id . '/' . $name, $content);

            return $name;
        }

        return null;
    }

    private function tellAdmins(string $text): void
    {
        try {
            app(Alerts::class)->send($text);
        } catch (\Throwable $e) {
            Log::warning('feedback:import: оповещение не отправлено', ['error' => $e->getMessage()]);
        }
    }
}
