<?php

namespace App\Services\Mail;

use App\Models\Webmail\Setting;
use App\Services\Cloud\Cloud;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Сборка письма: тело, подпись, вложения, ссылки на облако, выбор отправителя.
 *
 * Отдельно от отправки: здесь решается, каким письмо будет, там — куда оно пойдёт
 * и что останется в «Отправленных».
 */
class MailBuilder
{
    public function __construct(
        private readonly ImapSession $session,
        private readonly MailStore $store,
    ) {
    }


    /**
     * Собрать письмо из формы «Написать».
     *
     * @param array $form  to, cc, bcc (строки «Имя <адрес>, адрес»), subject, html, inReplyTo, references,
     *                     forwardOf {folder, uid} — переслать с вложениями исходного письма
     * @param UploadedFile[] $files
     * @param int[] $cloud  индексы файлов, которые уходят ссылкой (своё хранилище или Nextcloud)
     */
    public function build(array $form, array $files = [], array $cloud = [], bool $forSend = false): Email
    {
        $settings = Setting::for($this->session->user());
        $fromName = trim((string) ($settings['display_name'] ?? '')) ?: $this->session->user();
        $fromMail = $this->pickFrom($form['from'] ?? null);
        if ($fromMail !== strtolower($this->session->user()) && collect(self::sharedSenders($this->session->user()))->firstWhere('mail', $fromMail)) {
            // От имени общего ящика — его имя (настройки ящика, иначе имя из карточки), а не имя пишущего.
            $fromName = trim((string) (Setting::for($fromMail)['display_name'] ?? ''))
                ?: (\App\Models\Vmail\Mailbox::query()->where('username', $fromMail)->value('name') ?: $fromMail);
        }

        $email = (new Email())->from(new Address($fromMail, $fromName))->subject((string) ($form['subject'] ?? ''));

        foreach (['to', 'cc', 'bcc'] as $field) {
            $list = MailAddressList::parseAddresses((string) ($form[$field] ?? ''));
            if ($list) {
                $email->{$field}(...$list);
            }
        }

        // Message-ID нужен заранее: файлы в хранилище помечаются письмом, к которому приложены.
        $messageId = $email->generateMessageId();

        $html = (string) ($form['html'] ?? '');
        $subject = (string) ($form['subject'] ?? '');
        $published = [];   // токены уже положенных файлов — откатить, если письмо не соберётся
        try {
            $links = $this->publishToCloud($files, $cloud, $messageId, $subject, $published);
            // Вложения исходного письма (пересылка, «оставить вложения»): что крупнее порога — тоже ссылкой,
            // иначе пересылка письма с большим файлом упиралась бы в предел почтового сервера.
            [$kept, $keptLinks] = $this->keptAttachments($form, $forSend, $messageId, $subject, $published);
            $links = array_merge($links, $keptLinks);
        } catch (\Throwable $e) {
            \App\Services\Cloud\LocalFiles::discardTokens($published);
            throw $e;
        }
        if ($links) {
            $html .= $this->cloudBlockHtml($links);
        }
        // Картинки, встроенные редактором как data: (логотип в подписи, снимок из буфера) — во вложения с cid:
        // Gmail и часть клиентов data:-картинки в письмах не показывают, cid показывают все.
        $n = 0;
        // Раньше список форматов был короче, чем принимает кнопка «Картинка»: bmp, tiff, heic и другие
        // оставались в письме как data: и у получателя не показывались вовсе.
        $html = preg_replace_callback('#src=(["\'])data:(image/[a-z0-9.+-]+);base64,([A-Za-z0-9+/=\s]+)\1#i', function ($m) use ($email, &$n) {
            $data = base64_decode(preg_replace('/\s+/', '', $m[3]), true);
            if ($data === false || $data === '') {
                return $m[0];
            }
            $n++;
            $ext = match (strtolower($m[2])) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/svg+xml' => 'svg',
                'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
                default => preg_replace('/[^a-z0-9]/', '', substr(strtolower($m[2]), 6)) ?: 'img',
            };
            $name = 'image' . $n . '.' . $ext;
            $email->embed($data, $name, $m[2]);

            return 'src=' . $m[1] . 'cid:' . $name . $m[1];
        }, $html) ?? $html;
        $text = self::htmlToText($html);
        $email->html($html !== '' ? $html : '<p></p>')->text($text !== '' ? $text : ' ');

        // Идентификаторы приходят от клиента в любом виде («<a><b>» из Kerio, «a b», голый id) — нормализуем,
        // иначе Symfony получает склеенный «a@xb@y» и отказывается отправлять.
        $inReplyTo = MailStore::messageIds($form['inReplyTo'] ?? null);
        if ($inReplyTo) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $inReplyTo[0]);
        }
        $refs = MailStore::messageIds($form['references'] ?? null);
        if ($refs) {
            $email->getHeaders()->addIdHeader('References', $refs);
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
            if (! $file instanceof UploadedFile) {
                continue;
            }
            // Раньше недогруженный файл просто пропускался, и письмо уходило без него — молча.
            self::assertUploaded($file);
            $email->attachFromPath($file->getRealPath(), $file->getClientOriginalName(), $file->getMimeType());
        }

        foreach ($kept as [$content, $name, $mime]) {
            $email->attach($content, $name, $mime);
        }

        $email->getHeaders()->addTextHeader('X-Mailer', 'Почта ' . config('areas.default_domain'));
        // Message-ID фиксируем сами: иначе у отправленного письма и копии в «Отправленных» он разный.
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);

        return $email;
    }


    /** Положить отмеченные файлы в хранилище. @return array<int,array{name:string,size:int,url:string,expires:?string}> */
    /**
     * Имя файла, которое можно класть в письмо.
     *
     * Если имя осталось закодированным («=?utf-8?B?…?=»), отдавать его почтовой
     * библиотеке нельзя: она закодирует его ещё раз. В настоящем черновике нашёлся
     * файл с тремя слоями кодировки — каждое сохранение добавляло по слою, и человек
     * видел вместо имени служебную запись. Такое имя всё равно бесполезно, поэтому
     * заменяем понятным, сохраняя расширение.
     */
    private static function plainName(string $name, int $index): string
    {
        if (! str_contains($name, '=?')) {
            return $name;
        }
        $ext = '';
        if (preg_match('/\.([A-Za-z0-9]{1,8})$/', $name, $m)) {
            $ext = '.' . strtolower($m[1]);
        }

        return 'вложение-' . ($index + 1) . $ext;
    }

    private function publishToCloud(array $files, array $cloud, string $messageId, string $subject, array &$published): array
    {
        if (! $cloud || ! Cloud::enabled()) {
            return [];
        }
        $out = [];
        foreach ($files as $i => $file) {
            if (! in_array((int) $i, $cloud, true) || ! $file instanceof UploadedFile) {
                continue;
            }
            self::assertUploaded($file);
            $size = (int) $file->getSize();
            $r = Cloud::publish($file->getRealPath(), $file->getClientOriginalName(), $this->session->user(), $messageId, $subject);
            if (! empty($r['token'])) {
                $published[] = $r['token'];
            }
            $out[] = ['name' => $file->getClientOriginalName(), 'size' => $size, 'url' => $r['url'], 'expires' => $r['expires']];
        }

        return $out;
    }

    /** Письмо-источник вложений; null, если его уже нет на месте. */
    private function fetchSource(string $folder, int $uid): mixed
    {
        if ($folder === '' || $uid <= 0) {
            return null;
        }
        try {
            return $this->store->folder($folder)->query()->getMessageByUid($uid);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Файл дошёл до сервера целиком? Иначе — понятная ошибка, а не письмо без вложения. */
    private static function assertUploaded(UploadedFile $file): void
    {
        if ($file->isValid() && $file->getSize() > 0 && is_file($file->getRealPath())) {
            return;
        }
        $name = $file->getClientOriginalName() ?: 'файл';
        $why = match ($file->getError()) {
            UPLOAD_ERR_PARTIAL => 'загрузка оборвалась на середине',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'он больше, чем принимает сервер',
            UPLOAD_ERR_NO_FILE => 'файл пустой',
            default => $file->getSize() > 0 ? 'сервер не смог его сохранить' : 'файл пустой или не дочитался',
        };
        throw \App\Exceptions\MailException::invalid('«' . $name . '» не догрузился: ' . $why . '. Приложите файл заново и отправьте письмо ещё раз.');
    }

    /**
     * Вложения исходного письма при пересылке и «оставить вложения» при ответе.
     * keepIndexes — какие именно (снятые крестиком не берём); при отправке файлы крупнее порога
     * хранилища уходят ссылкой. Черновик (forSend=false) хранит их как есть.
     *
     * @return array{0:array<int,array{0:string,1:string,2:string}>,1:array<int,array{name:string,size:int,url:string,expires:?string}>}
     */
    private function keptAttachments(array $form, bool $forSend, string $messageId, string $subject, array &$published): array
    {
        if (empty($form['keepAttachments']) || empty($form['sourceFolder']) || empty($form['sourceUid'])) {
            return [[], []];
        }
        $src = $this->fetchSource((string) $form['sourceFolder'], (int) $form['sourceUid']);
        // Черновик при сохранении перекладывается под новым UID. Если письмо собрано со старым
        // (страховочная копия перед отправкой), берём вложения из актуального черновика.
        if (! $src && ! empty($form['draftUid']) && (int) $form['draftUid'] !== (int) $form['sourceUid']
            && strcasecmp((string) $form['sourceFolder'], $this->store->rolePath('drafts')) === 0) {
            $src = $this->fetchSource((string) $form['sourceFolder'], (int) $form['draftUid']);
        }
        // Исходное письмо удалили или переложили, пока письмо писали. Раньше вложения просто
        // не прикладывались, и получатель получал пересылку без файлов.
        abort_unless($src, 409, 'Исходное письмо больше не в той папке, поэтому его вложения не приложить. Снимите галочку «Вложения исходного письма» или откройте письмо заново.');
        $only = isset($form['keepIndexes']) && is_array($form['keepIndexes']) ? array_map('intval', $form['keepIndexes']) : null;
        $viaCloud = $forSend && Cloud::enabled();
        $threshold = Cloud::thresholdMb() * 1048576;
        $kept = [];
        $links = [];
        foreach ($src->getAttachments() as $i => $a) {
            if ($only !== null && ! in_array((int) $i, $only, true)) {
                continue;
            }
            // Имя — как показываем в веб-почте: библиотека отдаёт «=?utf-8?B?…?=» сырым, и при пересылке
            // получатель видел закодированную абракадабру вместо имени (обращение №20).
            $name = self::plainName(MailStore::attachmentName($a, 'attachment'), (int) $i);
            $content = (string) $a->getContent();
            if ($content === '') {
                continue;   // отправитель объявил вложение, но не догрузил: пересылать нечего
            }
            if ($viaCloud && strlen($content) >= $threshold) {
                $tmp = storage_path('app/private/tmp-fwd-' . bin2hex(random_bytes(6)));
                file_put_contents($tmp, $content);
                try {
                    $r = Cloud::publish($tmp, $name, $this->session->user(), $messageId, $subject);
                } finally {
                    @unlink($tmp);
                }
                if (! empty($r['token'])) {
                    $published[] = $r['token'];
                }
                $links[] = ['name' => $name, 'size' => strlen($content), 'url' => $r['url'], 'expires' => $r['expires']];
                continue;
            }
            $kept[] = [$content, $name, (string) $a->getMimeType()];
        }

        return [$kept, $links];
    }


    /**
     * Блок ссылок в письме — как у Mail.ru: заголовок, по файлу имя и «Ссылка для скачивания»,
     * внизу срок хранения. Читается в любом клиенте, в том числе без картинок и стилей.
     */
    private function cloudBlockHtml(array $links): string
    {
        $fmt = fn (int $b): string => \App\Support\Format::size($b);
        $rows = '';
        foreach ($links as $l) {
            $url = htmlspecialchars($l['url'], ENT_QUOTES);
            // Текст ссылки — раскодированный адрес: с кириллическим именем файла он читается,
            // а «%D0%A1%D1%85…» на полстроки — нет. Ведёт по-прежнему на закодированный адрес.
            $shown = htmlspecialchars(rawurldecode($l['url']));
            $rows .= '<div style="margin:0 0 10px">'
                . '<div style="font-weight:600;color:#1b2430">' . htmlspecialchars($l['name']) . ' <span style="font-weight:400;color:#6b7280">(' . $fmt($l['size']) . ')</span></div>'
                . '<div style="color:#4b5563">Ссылка для скачивания: <a href="' . $url . '" style="color:#1a56db;word-break:break-all">' . $shown . '</a></div>'
                . '</div>';
        }
        $until = array_filter(array_map(fn ($l) => $l['expires'], $links));
        $note = $until
            ? 'Файлы будут храниться до ' . date('d.m.Y', strtotime(min($until))) . '. Если срок истёк, попросите отправителя продлить ссылку.'
            : '';

        return '<div style="margin-top:20px;padding:16px 18px;border:1px solid #dde3ea;border-radius:10px;background:#f6f8fa;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.45;color:#1b2430">'
            . '<div style="font-weight:700;margin-bottom:10px">К этому письму приложены ссылки на следующие файлы:</div>' . $rows
            . ($note ? '<div style="color:#6b7280;font-size:12px;margin-top:4px">' . $note . '</div>' : '') . '</div>';
    }


    /**
     * Текстовая версия письма для тех, кто читает почту без разметки.
     * Раньше в переводы строк превращались только br, /p и /div: пункты списка и ячейки таблиц
     * слипались в одну строку («первоевтороетретье»).
     */
    private static function htmlToText(string $html): string
    {
        $s = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $s = preg_replace('#<li[^>]*>#i', "\n" . '• ', $s) ?? $s;
        $s = preg_replace('#</t[dh]>#i', "\t", $s) ?? $s;
        $s = preg_replace('#</(tr|h[1-6]|blockquote|table|ul|ol)>#i', "\n", $s) ?? $s;
        $s = preg_replace('#<br\s*/?>|</p>|</div>|<hr[^>]*>#i', "\n", $s) ?? $s;
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Неразрывный пробел из Word в текстовой версии выглядит как мусорный символ.
        $s = str_replace("\u{00A0}", ' ', $s);
        $s = preg_replace('/[ \t]+(\n)/', '$1', $s) ?? $s;
        $s = preg_replace('/\n{3,}/', "\n\n", $s) ?? $s;

        return trim($s);
    }


    /** Адрес «От»: свой, один из своих псевдонимов или общий ящик, где пользователь — владелец. */
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

        // Раньше здесь молча возвращался личный адрес. Список адресов собирается из базы vmail,
        // и стоит ей быть недоступной, как письмо от общего ящика уходило от лица сотрудника —
        // а узнавал он об этом только из «Отправленных». Лучше отказать до отправки.
        abort(422, "Отправить от имени {$wanted} сейчас не получается: этого адреса нет среди ваших. "
            . 'Обновите страницу и выберите отправителя заново, а если адрес общего ящика — проверьте, что доступ к нему ещё есть.');
    }


    /** Адреса, от имени которых пользователь может писать: сам ящик + его дополнительные адреса. */
    /** Общие ящики, где пользователь — владелец «Входящих»: от их имени можно писать. @return array<int,array{mail:string,name:string,primary:bool,shared:bool}> */
    public static function sharedSenders(string $user): array
    {
        return \Illuminate\Support\Facades\Cache::remember('sendas.' . strtolower($user), 120, function () use ($user) {
            $out = [];
            try {
                $owners = \Illuminate\Support\Facades\DB::connection('vmail')->table('share_folder')->where('to_user', strtolower($user))->pluck('from_user');
                $shares = new FolderShares();
                foreach ($owners as $owner) {
                    foreach ($shares->list($owner, 'INBOX') as $s) {
                        if ($s['mail'] === strtolower($user) && $s['level'] === 'owner') {
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
                // Подпись общего ящика — его собственная (задаётся в настройках самого ящика), не подпись пишущего.
                $s['signature'] = (string) (\App\Models\Webmail\Setting::for($s['mail'])['signature'] ?? '');
                $out[] = $s;
            }
        } catch (\Throwable) {
            // схема vmail недоступна — только основной адрес
        }

        return $out;
    }
}
