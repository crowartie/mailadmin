<?php

namespace App\Services\Mail;

use App\Models\Webmail\Recent;
use App\Models\Webmail\Setting;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Сборка и отправка писем: новое, ответ, пересылка, черновик, отложенная отправка.
 */
class Outgoing
{
    public function __construct(private readonly ImapSession $session, private readonly MailStore $store)
    {
    }

    /**
     * Собрать письмо из формы «Написать».
     *
     * @param array $form  to, cc, bcc (строки «Имя <адрес>, адрес»), subject, html, inReplyTo, references,
     *                     forwardOf {folder, uid} — переслать с вложениями исходного письма
     * @param UploadedFile[] $files
     */
    public function build(array $form, array $files = []): Email
    {
        $settings = Setting::for($this->session->user());
        $fromName = trim((string) ($settings['display_name'] ?? '')) ?: $this->session->user();
        $fromMail = $this->pickFrom($form['from'] ?? null);

        $email = (new Email())->from(new Address($fromMail, $fromName))->subject((string) ($form['subject'] ?? ''));

        foreach (['to', 'cc', 'bcc'] as $field) {
            $list = $this->parseAddresses((string) ($form[$field] ?? ''));
            if ($list) {
                $email->{$field}(...$list);
            }
        }

        $html = (string) ($form['html'] ?? '');
        $text = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/div>/i', "\n", $html))));
        $email->html($html !== '' ? $html : '<p></p>')->text($text !== '' ? $text : ' ');

        if (! empty($form['inReplyTo'])) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $form['inReplyTo']);
        }
        if (! empty($form['references'])) {
            $refs = preg_split('/\s+/', trim($form['references']), -1, PREG_SPLIT_NO_EMPTY);
            $email->getHeaders()->addIdHeader('References', array_map(fn ($r) => trim($r, '<>'), $refs));
        }
        if (! empty($form['priority'])) {
            $email->priority(Email::PRIORITY_HIGH);
        }
        if (! empty($form['receipt'])) {
            $email->getHeaders()->addMailboxHeader('Disposition-Notification-To', new Address($fromMail, $fromName));
        }

        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $email->attachFromPath($file->getRealPath(), $file->getClientOriginalName(), $file->getMimeType());
            }
        }

        // Вложения исходного письма при пересылке и «оставить вложения» при ответе.
        if (! empty($form['keepAttachments']) && ! empty($form['sourceFolder']) && ! empty($form['sourceUid'])) {
            $src = $this->store->folder($form['sourceFolder'])->query()->getMessageByUid((int) $form['sourceUid']);
            if ($src) {
                foreach ($src->getAttachments() as $a) {
                    $email->attach($a->getContent(), Charset::fix($a->getName()) ?: 'attachment', $a->getMimeType());
                }
            }
        }

        $email->getHeaders()->addTextHeader('X-Mailer', 'Почта ' . config('areas.default_domain'));
        // Message-ID фиксируем сами: иначе у отправленного письма и копии в «Отправленных» он разный.
        $email->getHeaders()->addIdHeader('Message-ID', $email->generateMessageId());

        return $email;
    }

    public static function messageId(Email $email): string
    {
        return trim((string) $email->getHeaders()->get('Message-ID')?->getBodyAsString(), '<>');
    }

    /** Отправить сейчас, положить копию в «Отправленные», отметить исходное отвеченным, убрать черновик. */
    public function send(Email $email, array $form): string
    {
        (new Mailer($this->session->smtp()))->send($email);
        $this->afterSend($this->store, $email, $form, $this->session->user());

        return self::messageId($email);
    }

    /** То же самое из планировщика: транспорт без авторизации, IMAP через master-пользователя. */
    public static function sendRaw(string $raw, string $from, array $recipients, TransportInterface $transport, MailStore $store): void
    {
        $envelope = new \Symfony\Component\Mailer\Envelope(new Address($from), array_map(fn ($r) => new Address($r), $recipients));
        $transport->send(new \Symfony\Component\Mime\RawMessage($raw), $envelope);
        $store->append($store->rolePath('sent'), $raw, ['\\Seen']);
    }

    public function afterSend(MailStore $store, Email $email, array $form, string $user): void
    {
        $raw = $email->toString();
        $store->append($store->rolePath('sent'), $raw, ['\\Seen']);

        if (! empty($form['answeredFolder']) && ! empty($form['answeredUid'])) {
            $store->flag($form['answeredFolder'], [(int) $form['answeredUid']], '\\Answered', true);
        }
        if (! empty($form['draftUid'])) {
            $drafts = $store->rolePath('drafts');
            $store->flag($drafts, [(int) $form['draftUid']], '\\Deleted', true);
            $store->client()->openFolder($drafts, true);
            $store->client()->getConnection()->expunge();
        }

        foreach (array_merge($email->getTo(), $email->getCc(), $email->getBcc()) as $addr) {
            Recent::remember($user, $addr->getAddress(), $addr->getName());
        }
    }

    /** Сохранить черновик; вернуть UID нового черновика. */
    public function saveDraft(Email $email, ?int $previousUid): ?int
    {
        $drafts = $this->store->rolePath('drafts');
        $uid = $this->store->append($drafts, $email->toString(), ['\\Seen', '\\Draft'], self::messageId($email));
        if ($previousUid) {
            $this->store->flag($drafts, [$previousUid], '\\Deleted', true);
            $this->store->client()->openFolder($drafts, true);
            $this->store->client()->getConnection()->expunge();
        }

        return $uid;
    }

    /** Разобрать «Имя <адрес>, адрес2; адрес3». */
    public function parseAddresses(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[;,]+(?![^<]*>)/u', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>\s*$/u', $piece, $m)) {
                $mail = trim($m[2]);
                $name = trim($m[1]);
            } else {
                $mail = trim($piece, " \t\"'<>");
                $name = '';
            }
            if (! filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                abort(422, "Неверный адрес: {$piece}");
            }
            $out[] = new Address($mail, $name);
        }

        return $out;
    }

    /** Адрес «От»: свой или один из своих псевдонимов; всё чужое — молча заменяем на свой. */
    private function pickFrom(?string $wanted): string
    {
        $user = $this->session->user();
        if (! $wanted || strcasecmp($wanted, $user) === 0) {
            return $user;
        }
        foreach ($this->identities() as $identity) {
            if (strcasecmp($identity['mail'], $wanted) === 0) {
                return $identity['mail'];
            }
        }

        return $user;
    }

    /** Адреса, от имени которых пользователь может писать: сам ящик + его дополнительные адреса. */
    public function identities(): array
    {
        $user = $this->session->user();
        $out = [['mail' => $user, 'primary' => true]];
        try {
            $aliases = \App\Models\Vmail\Forwarding::query()
                ->where('forwarding', $user)->where('is_alias', 1)->where('address', '!=', $user)
                ->pluck('address');
            foreach ($aliases as $a) {
                $out[] = ['mail' => $a, 'primary' => false];
            }
        } catch (\Throwable) {
            // схема vmail недоступна — только основной адрес
        }

        return $out;
    }
}
