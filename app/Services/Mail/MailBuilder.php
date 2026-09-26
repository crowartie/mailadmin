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
        $fromName = self::senderName($this->session->user(), $settings);
        $fromMail = $this->pickFrom($form['from'] ?? null);
        if ($fromMail !== strtolower($this->session->user()) && collect(self::sharedSenders($this->session->user()))->firstWhere('mail', $fromMail)) {
            // От имени общего ящика — его имя (настройки ящика, иначе имя из карточки), а не имя пишущего.
            $fromName = self::senderName($fromMail);
        }

        $email = (new Email())->from(new Address($fromMail, $fromName))->subject((string) ($form['subject'] ?? ''));

        $raw = [];
        foreach (['to', 'cc', 'bcc'] as $field) {
            $value = (string) ($form[$field] ?? '');
            $list = MailAddressList::parseAddresses($value, ! $forSend);
            if ($list) {
                $email->{$field}(...$list);
            }
            $raw[$field] = $value;
        }
        // Черновик с неверным адресом сохраняется; сами строки адресатов кладём в заголовок как есть,
        // чтобы при открытии человек увидел свою опечатку и исправил её, а не потерял адресата.
        if (! $forSend && self::hasInvalid($raw)) {
            // Пробелы через 76 знаков — чтобы длинный список можно было перенести по строкам (предел строки письма — 998).
            $email->getHeaders()->addTextHeader(self::RAW_RCPT_HEADER, trim(chunk_split(base64_encode((string) json_encode($raw, JSON_UNESCAPED_UNICODE)), 76, ' ')));
        }

        // Message-ID нужен заранее: файлы в хранилище помечаются письмом, к которому приложены.
        $messageId = $email->generateMessageId();

        $html = (string) ($form['html'] ?? '');
        $subject = (string) ($form['subject'] ?? '');
        $picked = self::pickedCloud($form);
        $published = [];   // токены уже положенных файлов — откатить, если письмо не соберётся
        try {
            $links = $this->publishToCloud($files, $cloud, $messageId, $subject, $published);
            // Вложения исходного письма (пересылка, «оставить вложения»): что крупнее порога — тоже ссылкой,
            // иначе пересылка письма с большим файлом упиралась бы в предел почтового сервера.
            [$kept, $keptLinks] = $this->keptAttachments($form, $forSend, $messageId, $subject, $published);
            $links = array_merge($links, $keptLinks);
            // Заранее положенные в хранилище: ссылка готова, файл уже проверен — ничего не заливаем.
            if ($forSend) {
                $links = array_merge($links, $this->stagedLinks($form));
            }
            // Файлы из облака сотрудника: ссылки берутся в момент отправки — действующие, с продлённым
            // сроком, если истёк. Файл успели удалить — письмо не уходит, человек видит, какого файла нет.
            if ($forSend && $picked) {
                $links = array_merge($links, \App\Services\Cloud\PersonalCloud::forUser($this->session->user())->attach(array_column($picked, 'path')));
            }
        } catch (\Throwable $e) {
            \App\Services\Cloud\LocalFiles::discardTokens($published);
            throw $e;
        }
        if ($links) {
            // Перед подписью, если она есть: так блок читается как часть письма, а не приписка после неё.
            $block = $this->cloudBlockHtml($links);
            $at = strpos($html, '<div class="sig"');
            $html = $at === false ? $html . $block : substr($html, 0, $at) . $block . substr($html, $at);
        }
        if (! $forSend) {
            // В черновике ссылок ещё нет — только отметка, какие файлы облака выбраны (вернутся карточками).
            foreach ($picked as $f) {
                $email->getHeaders()->addTextHeader(self::CLOUD_HEADER, base64_encode((string) json_encode($f, JSON_UNESCAPED_UNICODE)));
            }
            // Заранее положенные файлы: черновик помнит их, и при открытии они вернутся (раньше большие
            // файлы из черновика пропадали — их приходилось прикладывать заново).
            foreach (self::stagedTokens($form) as $token) {
                $email->getHeaders()->addTextHeader(self::STAGED_HEADER, $token);
            }
        }
        // Картинки исходного письма (ответ с цитатой, пересылка, открытый черновик) приходят ссылками
        // на наш же сервер — получатель их не откроет. Раньше так и уходили: битые картинки у получателя.
        $html = self::embedServerImages($html, function (string $path, int $uid, int $index) {
            $part = $this->store->attachment($path, $uid, $index);

            return [$part->getMimeType(), $part->getContent()];
        });
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

        // Письма, приложенные целиком (обращение №39): получатель открывает исходное письмо
        // со всеми заголовками, а не пересказ в цитате.
        foreach ($this->attachedMails($form) as [$raw, $name]) {
            $email->attach($raw, $name, 'message/rfc822');
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

    /**
     * Исходники писем, которые приложены к новому письму как файлы .eml.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function attachedMails(array $form): array
    {
        $out = [];
        foreach ((array) ($form['attachMessages'] ?? []) as $one) {
            $folder = (string) ($one['folder'] ?? '');
            $uid = (int) ($one['uid'] ?? 0);
            if ($folder === '' || $uid <= 0) {
                continue;
            }
            try {
                $raw = $this->store->raw($folder, $uid);
            } catch (\Throwable) {
                $raw = '';
            }
            // Письмо могли удалить или переложить, пока человек писал. Молча отправить без него нельзя:
            // получатель ждёт именно пересылаемую переписку.
            if (trim($raw) === '') {
                throw \App\Exceptions\MailException::notFound('Письмо «' . mb_substr((string) ($one['name'] ?? 'без темы'), 0, 60) . '» больше не найти в папке — уберите его из вложений и отправьте снова.');
            }
            $name = trim((string) ($one['name'] ?? ''));
            $out[] = [$raw, $name !== '' ? AttachedMessage::fileName($name) : AttachedMessage::fileName('письмо')];
        }

        return $out;
    }

    /** Файл дошёл до сервера целиком? Иначе — понятная ошибка, а не письмо без вложения. */
    public static function assertUploaded(UploadedFile $file): void
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
        // Источник — сам черновик: значит, его сохранили или удалили в другом окне или на другом
        // устройстве (черновик при сохранении получает новый номер). Совет про «галочку» тут сбивал.
        $fromDraft = strcasecmp((string) $form['sourceFolder'], $this->store->rolePath('drafts')) === 0;
        abort_unless($src, 409, $fromDraft
            ? 'Этот черновик изменили или удалили в другом окне либо на другом устройстве — его вложения отсюда не сохранить. Откройте черновик заново из папки «Черновики».'
            : 'Исходное письмо больше не в той папке, поэтому его вложения не приложить. Снимите галочку «Вложения исходного письма» или откройте письмо заново.');
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
     * Имя отправителя в «От кого»: своё из настроек веб-почты, иначе ФИО из справочника, иначе адрес.
     * Раньше без своего имени (так у 112 из 115 ящиков) в письме стояло «"vvv@innotec.su" <vvv@innotec.su>»:
     * получатель не видел, кто пишет, а спам-фильтры (Яндекс) принимают такое за робота.
     */
    public static function senderName(string $mail, ?array $settings = null): string
    {
        $mail = strtolower(trim($mail));
        $own = trim((string) (($settings ?? Setting::for($mail))['display_name'] ?? ''));
        if ($own !== '' && strcasecmp($own, $mail) !== 0) {
            return $own;
        }
        $dir = trim((string) \App\Models\Vmail\Mailbox::query()->where('username', $mail)->value('name'));

        return $dir !== '' ? $dir : $mail;
    }

    /** Заголовок черновика с выбранным файлом облака (base64 от JSON {path, name, size}). */
    public const CLOUD_HEADER = 'X-Mailadmin-Cloud';

    /** Токен заранее положенного файла — в черновике, по заголовку на файл. */
    public const STAGED_HEADER = 'X-Mailadmin-Staged';

    /** @return string[] */
    public static function stagedTokens(array $form): array
    {
        $out = [];
        foreach ((array) ($form['staged'] ?? []) as $s) {
            $t = is_array($s) ? (string) ($s['token'] ?? '') : '';
            if (preg_match('/^[A-Za-z0-9_-]{20,64}$/', $t)) {
                $out[$t] = true;
            }
        }

        return array_slice(array_keys($out), 0, 20);
    }

    /** Ссылки на заранее положенные файлы. Файла нет (брошенный черновик убран уборкой) — письмо не уходит. */
    private function stagedLinks(array $form): array
    {
        $tokens = self::stagedTokens($form);
        if (! $tokens) {
            return [];
        }
        $found = \App\Services\Cloud\LocalFiles::staged($this->session->user(), $tokens)->keyBy('token');
        $out = [];
        foreach ((array) $form['staged'] as $s) {
            $f = $found[(string) ($s['token'] ?? '')] ?? null;
            if (! $f || ! is_file($f->fullPath())) {
                throw \App\Exceptions\MailException::notFound('«' . mb_substr((string) ($s['name'] ?? 'файл'), 0, 80) . '» больше нет в хранилище — уберите его из письма и приложите заново');
            }
            $out[] = ['name' => $f->name, 'size' => (int) $f->size, 'url' => $f->url(), 'expires' => $f->expires_at?->toDateString()];
        }

        return $out;
    }

    /** Заранее положенные файлы черновика — карточками для окна письма. @return array<int,array{token:string,name:string,size:int}> */
    public static function draftStaged(string $head, string $user): array
    {
        preg_match_all('/^' . self::STAGED_HEADER . ':\s*([A-Za-z0-9_-]{20,64})\s*$/mi', $head, $m);

        return \App\Services\Cloud\LocalFiles::staged($user, $m[1] ?? [])
            ->map(fn ($f) => ['token' => $f->token, 'name' => $f->name, 'size' => (int) $f->size])->values()->all();
    }

    /** Адресаты черновика как их набрали — только когда среди них есть неверный адрес. */
    public const RAW_RCPT_HEADER = 'X-Mailadmin-Draft-Recipients';

    private static function hasInvalid(array $raw): bool
    {
        foreach ($raw as $value) {
            if (count(MailAddressList::parseAddresses($value, true)) < count(array_filter(array_map('trim', MailAddressList::splitAddresses($value)), 'strlen'))) {
                return true;
            }
        }

        return false;
    }

    /** Адресаты черновика из заголовка (см. RAW_RCPT_HEADER). @return array{to?:string,cc?:string,bcc?:string} */
    public static function draftRawRecipients(string $head): array
    {
        // Значение могло быть перенесено на несколько строк (продолжения начинаются с пробела).
        if (! preg_match('/^' . self::RAW_RCPT_HEADER . ':([^\r\n]*(?:\r?\n[ \t][^\r\n]*)*)/mi', $head, $m)) {
            return [];
        }
        $v = json_decode((string) base64_decode(preg_replace('/\s+/', '', $m[1]), true), true);

        return is_array($v) ? array_map('strval', array_intersect_key($v, ['to' => 1, 'cc' => 1, 'bcc' => 1])) : [];
    }

    /** Файлы облака из формы: путь обязателен, повторы убраны, не больше 20. @return array<int,array{path:string,name:string,size:int}> */
    public static function pickedCloud(array $form): array
    {
        $out = [];
        foreach ((array) ($form['cloudFiles'] ?? []) as $f) {
            $path = is_array($f) ? trim((string) ($f['path'] ?? '')) : '';
            if ($path === '' || isset($out[$path])) {
                continue;
            }
            $out[$path] = ['path' => $path, 'name' => (string) ($f['name'] ?? basename($path)), 'size' => (int) ($f['size'] ?? 0)];
        }

        return array_slice(array_values($out), 0, 20);
    }

    /** Выбранные файлы облака из заголовков черновика. */
    public static function draftCloudFiles(string $head): array
    {
        preg_match_all('/^' . self::CLOUD_HEADER . ':\s*([A-Za-z0-9+\/=]+)/mi', $head, $m);
        $files = [];
        foreach ($m[1] as $b64) {
            $f = json_decode((string) base64_decode($b64, true), true);
            if (is_array($f)) {
                $files[] = $f;
            }
        }

        return self::pickedCloud(['cloudFiles' => $files]);
    }

    /**
     * Блок ссылок в письме — как у Mail.ru: заголовок, по файлу имя и «Ссылка для скачивания»,
     * внизу срок хранения. Читается в любом клиенте, в том числе без картинок и стилей.
     */
    private function cloudBlockHtml(array $links): string
    {
        return self::linksBlock($links);
    }

    /**
     * Блок «К этому письму приложены ссылки…» — один вид для больших вложений и для файлов
     * из личного облака. $links: name, size, url, expires, password (ссылка с паролем).
     */
    public static function linksBlock(array $links): string
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
                . (! empty($l['password']) ? '<div style="color:#6b7280;font-size:12px">Ссылка защищена паролем — его сообщит отправитель.</div>' : '')
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

    /**
     * Ссылки на картинки исходного письма (/mail/api/message/<папка>/<номер>/attachment/<n>) — в data:,
     * дальше они встраиваются в письмо как обычные картинки (cid:).
     *
     * Такие ссылки ставит просмотр письма: картинки из текста он отдаёт ссылкой, а не строкой data:
     * (см. MessageBody). Окно письма подменяет их само (Editor.embedServerImages), здесь — запасной
     * путь: письмо отправили раньше, чем картинки успели подгрузиться. Исходного письма уже нет —
     * картинку убираем: ссылка на чужой для получателя сервер ему ни к чему.
     */
    /** @param callable(string $path, int $uid, int $index): array{0:string,1:string} $fetch тип и содержимое части письма */
    public static function embedServerImages(string $html, callable $fetch): string
    {
        if (! str_contains($html, '/mail/api/message/')) {
            return $html;
        }
        $cache = [];

        return preg_replace_callback('#src=(["\'])(?:https?://[^/"\']+)?/mail/api/message/([^/"\'?]+)/(\d+)/attachment/(\d+)(?:\?[^"\']*)?\1#i', function ($m) use (&$cache, $fetch) {
            $key = $m[2] . '/' . $m[3] . '/' . $m[4];
            if (! array_key_exists($key, $cache)) {
                $cache[$key] = null;
                try {
                    [$mime, $data] = $fetch(rawurldecode($m[2]), (int) $m[3], (int) $m[4]);
                    $mime = strtolower((string) $mime);
                    $data = (string) $data;
                    if (str_starts_with($mime, 'image/') && $data !== '' && strlen($data) <= 10_000_000) {
                        $cache[$key] = 'data:' . $mime . ';base64,' . base64_encode($data);
                    }
                } catch (\Throwable) {
                    // письма уже нет или оно недоступно — картинку убираем ниже
                }
            }

            return 'src=' . $m[1] . ($cache[$key] ?? '') . $m[1];
        }, $html) ?? $html;
    }
}
