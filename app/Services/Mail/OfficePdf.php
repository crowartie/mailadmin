<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Офисный документ → PDF для предпросмотра (LibreOffice без окна).
 *
 * Одно место на всех: вложения писем и файлы из хранилища больших вложений.
 * Готовые PDF лежат в кэше по хэшу содержимого, конвертер запускается по одному —
 * два soffice разом съедают всю память.
 */
final class OfficePdf
{
    public const TYPES = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];

    /** Больше этого в предпросмотр не берём: конвертер будет молотить минуты. */
    public const MAX_BYTES = 25 * 1024 * 1024;

    public static function supports(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::TYPES, true);
    }

    /**
     * Путь к PDF для документа, лежащего в файле $src (имя нужно ради расширения).
     */
    public static function convertFile(string $src, string $name): string
    {
        if (! is_file($src) || filesize($src) === 0) {
            throw MailException::notFound('Документ пустой');
        }
        if (filesize($src) > self::MAX_BYTES) {
            throw MailException::tooLarge('Документ слишком большой для предпросмотра — скачайте его');
        }
        if (! self::supports($name)) {
            throw MailException::unsupported('Этот тип файла не показываем — скачайте его');
        }

        return self::convert(fn () => file_get_contents($src), $name, sha1_file($src));
    }

    /**
     * То же для содержимого в памяти (вложение письма уже прочитано из IMAP).
     */
    public static function convertContent(string $content, string $name): string
    {
        if ($content === '') {
            throw MailException::notFound('Вложение пустое');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw MailException::tooLarge('Документ слишком большой для предпросмотра — скачайте его');
        }
        if (! self::supports($name)) {
            throw MailException::unsupported('Этот тип файла не показываем — скачайте его');
        }

        return self::convert(fn () => $content, $name, sha1($content));
    }

    /** @param  callable():string  $content  читается только когда PDF ещё нет в кэше */
    private static function convert(callable $content, string $name, string $hash): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $dir = storage_path('app/private/preview');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $pdf = $dir . '/' . $hash . '.pdf';
        if (is_file($pdf) && filesize($pdf) > 0) {
            touch($pdf);

            return $pdf;
        }
        $lock = Cache::lock('office-preview', 90);
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
            file_put_contents($src, $content());
            // Свой профиль в каталоге кэша: у www-data нет домашней папки, без профиля soffice не стартует.
            $cmd = ['soffice', '-env:UserInstallation=file://' . $dir . '/profile', '--headless', '--norestore', '--convert-to', 'pdf', '--outdir', $work, $src];
            $p = new Process($cmd, $work, ['HOME' => $dir], null, 120);
            $p->run();
            $out = $work . '/in.pdf';
            if (! $p->isSuccessful() || ! is_file($out)) {
                Log::warning('office-preview: ' . $name . ': ' . trim($p->getErrorOutput() . ' ' . $p->getOutput()));
                File::deleteDirectory($work);
                throw MailException::upstream('Не удалось подготовить предпросмотр — скачайте документ');
            }
            rename($out, $pdf);
            File::deleteDirectory($work);

            return $pdf;
        } finally {
            $lock->release();
        }
    }
}
