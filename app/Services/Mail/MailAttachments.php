<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;

/** Вложения: отдельная часть письма, архив со всеми и предпросмотр офисного документа в PDF. */
class MailAttachments
{
    public function __construct(
        private readonly Client $client,
        private readonly FolderTree $tree,
        private readonly MessageFetch $fetch,
    ) {
    }


    /**
     * Все вложения письма одним ZIP (встроенные картинки из тела не берём). Возвращает путь к временному файлу,
     * имя для скачивания и число файлов; временный файл удаляет вызывающий (deleteFileAfterSend).
     *
     * @return array{path:string,name:string,count:int}
     */
    /**
     * Письмо, приложенное к письму (.eml): разобранная шапка, тело и его вложения —
     * чтобы показать переписку прямо в почте, а не скачивать файл (обращение №39).
     *
     * @return array<string,mixed>
     */
    public function attachedMessage(string $path, int $uid, int $index): array
    {
        $a = $this->attachment($path, $uid, $index);
        if (! AttachedMessage::looksLikeMail($a->getMimeType(), $a->getName())) {
            throw MailException::unsupported('Это вложение — не письмо');
        }

        return AttachedMessage::parse((string) $a->getContent(), $a->getName()) + ['index' => $index];
    }

    /** Вложение изнутри приложенного письма. */
    public function attachedPart(string $path, int $uid, int $index, int $sub): MailPart
    {
        $a = $this->attachment($path, $uid, $index);
        if (! AttachedMessage::looksLikeMail($a->getMimeType(), $a->getName())) {
            throw MailException::unsupported('Это вложение — не письмо');
        }

        return AttachedMessage::part((string) $a->getContent(), $sub);
    }

    /** Расширение файла по типу — для вложений, у которых нет имени. */
    private const EXT_BY_TYPE = [
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/bmp' => 'bmp', 'image/tiff' => 'tif', 'image/heic' => 'heic',
        'image/svg+xml' => 'svg', 'image/x-icon' => 'ico',
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/html' => 'html', 'text/csv' => 'csv',
        'text/xml' => 'xml', 'application/xml' => 'xml', 'application/json' => 'json', 'text/rtf' => 'rtf',
        'application/rtf' => 'rtf',
        'message/rfc822' => 'eml',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/zip' => 'zip', 'application/x-zip-compressed' => 'zip',
        'application/x-rar-compressed' => 'rar', 'application/vnd.rar' => 'rar',
        'application/x-7z-compressed' => '7z', 'application/gzip' => 'gz',
        'application/vnd.ms-outlook' => 'msg',
        'audio/mpeg' => 'mp3', 'video/mp4' => 'mp4', 'audio/ogg' => 'ogg',
    ];


    /**
     * Одно вложение письма по его номеру.
     *
     * Сначала пробуем взять его одной частью: содержимое каждой картинки из текста
     * письма браузер запрашивает отдельно, и выкачивать ради неё письмо целиком (а с ним
     * и все прочие вложения) — это секунды и десятки мегабайт памяти на каждый запрос.
     */
    public function attachment(string $path, int $uid, int $index): MailPart
    {
        $parts = Structure::of($this->client, $path, $uid);
        if ($parts !== null) {
            $list = Structure::attachments($parts);
            if (! isset($list[$index])) {
                throw MailException::notFound('Вложение не найдено');
            }
            $a = $list[$index];
            $got = Structure::fetchParts($this->client, $path, $uid, [$a]);
            if (isset($got[$a['no']])) {
                $name = $a['name'] !== '' ? $a['name'] : 'вложение-' . ($index + 1);

                return new MailPart($name, (string) $a['mime'], $got[$a['no']]);
            }
        }

        // Структура не разобралась или часть не пришла — читаем письмо целиком, как раньше.
        // Письмо могли переложить или удалить, пока страница была открыта: браузер продолжает
        // запрашивать его картинки, и раньше каждая отвечала 502 с записью в журнал ошибок.
        try {
            $message = $this->tree->folder($path)->query()->getMessageByUid($uid);
        } catch (\Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException) {
            $message = null;
        }
        if (! $message) {
            throw MailException::notFound('Письмо не найдено');
        }
        $list = $message->getAttachments()->values();
        if (! isset($list[$index])) {
            throw MailException::notFound('Вложение не найдено');
        }

        return MailPart::fromAttachment($list[$index], 'вложение-' . ($index + 1));
    }


    public function attachmentsZip(string $path, int $uid): array
    {
        $message = $this->fetch->messageOrNull($path, $uid);
        if (! $message) {
            throw MailException::notFound('Письмо не найдено — возможно, его удалили или переложили в другой вкладке');
        }
        $html = (string) ($message->getHTMLBody() ?? '');
        $tmp = tempnam(sys_get_temp_dir(), 'att');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw MailException::upstream('Не удалось создать архив');
        }
        $used = [];
        $count = 0;
        // Нумерация та же, что в списке вложений письма (см. 157).
        foreach ($message->getAttachments()->values() as $i => $a) {
            /** @var Attachment $a */
            $cid = trim((string) ($a->id ?? ''), '<>');
            if ($cid !== '' && $html !== '' && str_contains($html, 'cid:' . $cid)) {
                continue;   // картинка из тела письма
            }
            // Пустая часть — отправитель объявил файл, но тела не прислал (так рвётся отправка
            // с телефона). Класть в архив нулевой файл нельзя: распаковав его, человек решит,
            // что вложение испортила почта. В письме такие части подписаны «файл не дошёл».
            if (strlen((string) $a->getContent()) <= 2) {
                continue;
            }
            $name = Mime::attachmentName($a, 'вложение-' . ($i + 1));
            $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1f]+#', '_', $name) ?: 'вложение-' . ($i + 1);
            // Часть без имени (библиотека подставляет кусок Content-ID) — добавим расширение по типу, чтобы файл открывался
            if (! str_contains($name, '.')) {
                // Таблица знала шесть типов, и вложенное письмо, docx, xlsx или zip попадали
                // в архив как «вложение-1», который Windows не открывает.
                $ext = self::EXT_BY_TYPE[strtolower((string) $a->getMimeType())] ?? null;
                if ($ext) {
                    $name .= '.' . $ext;
                }
            }
            // Одинаковые имена — нумеруем, иначе ZIP молча перезапишет
            $base = $name;
            for ($n = 2; isset($used[mb_strtolower($name)]); $n++) {
                $dot = strrpos($base, '.');
                $name = $dot ? substr($base, 0, $dot) . " ($n)" . substr($base, $dot) : "$base ($n)";
            }
            $used[mb_strtolower($name)] = true;
            $zip->addFromString($name, (string) $a->getContent());
            $count++;
        }
        $zip->close();
        $subject = trim((string) Charset::header((string) ($message->getSubject()->first() ?? '')));
        $subject = preg_replace('#[\\\\/:*?"<>|\x00-\x1f]+#', ' ', $subject) ?: '';
        $file = trim(mb_substr($subject, 0, 60)) ?: 'письмо-' . $uid;

        return ['path' => $tmp, 'name' => 'Вложения — ' . $file . '.zip', 'count' => $count];
    }


    /**
     * Предпросмотр офисного вложения: LibreOffice (headless) переводит документ в PDF, результат кэшируется по
     * содержимому файла (storage/app/private/preview, чистится раз в сутки старше недели). Преобразования идут
     * по одному — процессор слабый, а конвертер прожорливый. Возвращает путь к PDF.
     */
    public function attachmentPreviewPdf(string $path, int $uid, int $index): string
    {
        $a = $this->attachment($path, $uid, $index);

        return OfficePdf::convertContent((string) $a->getContent(), (string) $a->getName());
    }
}
