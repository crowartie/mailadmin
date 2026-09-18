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
        $message = $this->tree->folder($path)->query()->getMessageByUid($uid);
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
        $content = (string) $a->getContent();
        if ($content === '') {
            throw MailException::notFound('Вложение пустое');
        }
        if (strlen($content) > 25 * 1024 * 1024) {
            throw MailException::tooLarge('Документ слишком большой для предпросмотра — скачайте его');
        }
        $name = $a->getName();
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'], true)) {
            throw MailException::unsupported('Этот тип файла не показываем — скачайте его');
        }
        $dir = storage_path('app/private/preview');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $pdf = $dir . '/' . sha1($content) . '.pdf';
        if (is_file($pdf) && filesize($pdf) > 0) {
            touch($pdf);

            return $pdf;
        }
        $lock = \Illuminate\Support\Facades\Cache::lock('office-preview', 90);
        if (! $lock->block(60)) {
            throw MailException::busy('Конвертер занят — попробуйте через минуту');
        }
        try {
            if (is_file($pdf) && filesize($pdf) > 0) {   // пока ждали, сделал кто-то другой
                return $pdf;
            }
            $work = $dir . '/tmp-' . bin2hex(random_bytes(6));
            mkdir($work, 0750, true);
            $src = $work . '/in.' . $ext;
            file_put_contents($src, $content);
            // Свой профиль в каталоге кэша: у www-data нет домашней папки, без профиля soffice не стартует.
            $cmd = ['soffice', '-env:UserInstallation=file://' . $dir . '/profile', '--headless', '--norestore', '--convert-to', 'pdf', '--outdir', $work, $src];
            $p = new \Symfony\Component\Process\Process($cmd, $work, ['HOME' => $dir], null, 120);
            $p->run();
            $out = $work . '/in.pdf';
            if (! $p->isSuccessful() || ! is_file($out)) {
                \Illuminate\Support\Facades\Log::warning('office-preview: ' . $name . ': ' . trim($p->getErrorOutput() . ' ' . $p->getOutput()));
                \Illuminate\Support\Facades\File::deleteDirectory($work);
                throw MailException::upstream('Не удалось подготовить предпросмотр — скачайте документ');
            }
            rename($out, $pdf);
            \Illuminate\Support\Facades\File::deleteDirectory($work);

            return $pdf;
        } finally {
            $lock->release();
        }
    }
}
