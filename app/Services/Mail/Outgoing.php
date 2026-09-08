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
     * @param int[] $cloud  индексы файлов, которые уходят ссылкой через Nextcloud
     */
    public function build(array $form, array $files = [], array $cloud = []): Email
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
        $links = $this->publishToCloud($files, $cloud);
        if ($links) {
            $html .= $this->cloudBlockHtml($links);
        }
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

        foreach ($files as $i => $file) {
            if (in_array((int) $i, $cloud, true) && $links) {
                continue; // ушёл ссылкой
            }
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

    /** Загрузить отмеченные файлы в Nextcloud. @return array<int,array{name:string,size:int,url:string,expires:?string}> */
    private function publishToCloud(array $files, array $cloud): array
    {
        if (! $cloud || ! \App\Services\Cloud\Nextcloud::enabled()) {
            return [];
        }
        $nc = new \App\Services\Cloud\Nextcloud();
        $out = [];
        foreach ($files as $i => $file) {
            if (! in_array((int) $i, $cloud, true) || ! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $r = $nc->publish($file->getRealPath(), $file->getClientOriginalName(), $this->session->user());
            $out[] = ['name' => $file->getClientOriginalName(), 'size' => (int) $file->getSize(), 'url' => $r['url'], 'expires' => $r['expires']];
        }

        return $out;
    }

    private function cloudBlockHtml(array $links): string
    {
        $fmt = function (int $b): string {
            return $b >= 1073741824 ? round($b / 1073741824, 1) . ' ГБ' : ($b >= 1048576 ? round($b / 1048576, 1) . ' МБ' : max(1, (int) round($b / 1024)) . ' КБ');
        };
        $rows = '';
        foreach ($links as $l) {
            $rows .= '<div style="margin:4px 0"><a href="' . htmlspecialchars($l['url']) . '" style="color:#1a56db">' . htmlspecialchars($l['name']) . '</a> <span style="color:#777">(' . $fmt($l['size']) . ')</span></div>';
        }
        $until = array_filter(array_map(fn ($l) => $l['expires'], $links));
        $note = $until ? 'Ссылки действуют до ' . date('d.m.Y', strtotime(min($until))) . '.' : '';

        return '<div style="margin-top:16px;padding:12px 14px;border:1px solid #dde3ea;border-radius:8px;background:#f6f8fa;font-family:sans-serif;font-size:14px">'
            . '<div style="font-weight:600;margin-bottom:6px">Файлы к письму (через облако)</div>' . $rows
            . ($note ? '<div style="color:#777;font-size:12px;margin-top:6px">' . $note . '</div>' : '') . '</div>';
    }

    public static function messageId(Email $email): string
    {
        return trim((string) $email->getHeaders()->get('Message-ID')?->getBodyAsString(), '<>');
    }

    /** Отправить сейчас, положить копию в «Отправленные», отметить исходное отвеченным, убрать черновик. */
    public function send(Email $email, array $form): string
    {
        $from = strtolower($email->getFrom()[0]->getAddress());
        $shared = collect(self::sharedSenders($this->session->user()))->firstWhere('mail', $from);
        if ($shared) {
            // Письмо от имени общего ящика: SMTP-авторизация под своим логином не пропустит чужой адрес,
            // поэтому шлём через локальный relay (как сам сервер), а копию кладём в «Отправленные» общего ящика.
            (new Mailer(ImapSession::smtpLocal()))->send($email);
            try {
                $ownerStore = new MailStore(ImapSession::master($from));
                $ownerStore->append($ownerStore->rolePath('sent'), $email->toString(), ['\\Seen']);
            } catch (\Throwable) {
                $this->store->append($this->store->rolePath('sent'), $email->toString(), ['\\Seen']);
            }
            $this->afterSend($this->store, $email, $form, $this->session->user(), false);
        } else {
            (new Mailer($this->session->smtp()))->send($email);
            $this->afterSend($this->store, $email, $form, $this->session->user());
        }

        return self::messageId($email);
    }

    /** То же самое из планировщика: транспорт без авторизации, IMAP через master-пользователя. */
    public static function sendRaw(string $raw, string $from, array $recipients, TransportInterface $transport, MailStore $store): void
    {
        $envelope = new \Symfony\Component\Mailer\Envelope(new Address($from), array_map(fn ($r) => new Address($r), $recipients));
        $transport->send(new \Symfony\Component\Mime\RawMessage($raw), $envelope);
        $store->append($store->rolePath('sent'), $raw, ['\\Seen']);
    }

    public function afterSend(MailStore $store, Email $email, array $form, string $user, bool $copyToSent = true): void
    {
        $raw = $email->toString();
        if ($copyToSent) {
            $store->append($store->rolePath('sent'), $raw, ['\\Seen']);
        }

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
    /** Общие ящики, где пользователь — редактор «Входящих»: от их имени можно писать. @return array<int,array{mail:string,name:string,primary:bool,shared:bool}> */
    public static function sharedSenders(string $user): array
    {
        return \Illuminate\Support\Facades\Cache::remember('sendas.' . strtolower($user), 120, function () use ($user) {
            $out = [];
            try {
                $owners = \Illuminate\Support\Facades\DB::connection('vmail')->table('share_folder')->where('to_user', strtolower($user))->pluck('from_user');
                $shares = new FolderShares();
                foreach ($owners as $owner) {
                    foreach ($shares->list($owner, 'INBOX') as $s) {
                        if ($s['mail'] === strtolower($user) && $s['level'] === 'editor') {
                            $name = \App\Models\Vmail\Mailbox::query()->where('username', $owner)->value('name') ?: $owner;
                            $out[] = ['mail' => strtolower($owner), 'name' => $name, 'primary' => false, 'shared' => true];
                        }
                    }
                }
            } catch (\Throwable) {
                // doveadm недоступен — без общих отправителей
            }

            return $out;
        });
    }

    public function identities(): array
    {
        $user = $this->session->user();
        $out = [['mail' => $user, 'primary' => true]];
        try {
            $aliases = \App\Models\Vmail\Forwarding::query()
                ->where('forwarding', $user)->where('is_alias', 1)->where('address', '!=', $user)
                ->pluck('address');
            $local = \App\Models\Vmail\Domain::query()->pluck('domain')->map('strtolower')->all();
            foreach ($aliases as $a) {
                // Писать можно только с адресов наших доменов: чужая почта (mail.ru и т.п.) отправителем быть не может.
                if (in_array(strtolower(substr(strrchr($a, '@') ?: '@', 1)), $local, true)) {
                    $out[] = ['mail' => $a, 'primary' => false];
                }
            }
            foreach (self::sharedSenders($user) as $s) {
                $out[] = $s;
            }
        } catch (\Throwable) {
            // схема vmail недоступна — только основной адрес
        }

        return $out;
    }
}
