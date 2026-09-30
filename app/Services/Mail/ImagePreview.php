<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Фото с iPhone (HEIC/HEIF) → JPEG для просмотрщика (обращение №64): Chrome и Edge на Windows HEIC не
 * показывают. Переводит heif-convert (libheif + модуль libde265); поворот снимка применяется. Кэш — по
 * содержимому, рядом с PDF-предпросмотрами; конвертер — с лимитом памяти и времени.
 */
final class ImagePreview
{
    public const TYPES = ['heic', 'heif'];
    public const MAX_BYTES = 40 * 1024 * 1024;

    public static function supports(string $name, string $mime = ''): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::TYPES, true)
            || (bool) preg_match('~^image/hei[cf]~i', $mime);
    }

    public static function fromContent(string $content, string $name, string $mime = ''): string
    {
        if (! self::supports($name, $mime)) {
            throw MailException::unsupported('Это не фото HEIC — скачайте файл');
        }
        if ($content === '') {
            throw MailException::notFound('Вложение пустое');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw MailException::tooLarge('Фото слишком большое для просмотра — скачайте его');
        }

        return self::make(fn () => $content, sha1($content));
    }

    public static function fromFile(string $src, string $name, string $mime = ''): string
    {
        if (! self::supports($name, $mime)) {
            throw MailException::unsupported('Это не фото HEIC — скачайте файл');
        }
        if (! is_file($src) || filesize($src) > self::MAX_BYTES) {
            throw MailException::tooLarge('Фото слишком большое для просмотра — скачайте его');
        }

        return self::make(fn () => (string) file_get_contents($src), sha1_file($src));
    }

    /** @param callable():string $content */
    private static function make(callable $content, string $hash): string
    {
        $dir = storage_path('app/private/preview');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $jpg = $dir . '/' . $hash . '.jpg';
        if (is_file($jpg) && filesize($jpg) > 0) {
            touch($jpg);

            return $jpg;
        }
        $lock = Cache::lock('preview-image', 70);
        if (! $lock->block(60)) {
            throw MailException::busy('Просмотр фото занят — попробуйте через минуту');
        }
        $work = $dir . '/tmp-' . bin2hex(random_bytes(6));
        try {
            if (is_file($jpg) && filesize($jpg) > 0) {
                return $jpg;
            }
            mkdir($work, 0750, true);
            file_put_contents($work . '/in.heic', $content());
            $cmd = ['heif-convert', '-q', '88', $work . '/in.heic', $work . '/out.jpg'];
            if (is_executable('/usr/bin/prlimit')) {
                $cmd = ['/usr/bin/prlimit', '--as=2147483648', '--cpu=60', '--core=0', '--', ...$cmd];
            }
            $p = new Process($cmd, $work, null, null, 60);
            $p->run();
            // В HEIC бывает несколько снимков (серия, живое фото): heif-convert пишет out-1.jpg, out-2.jpg… — берём первый.
            $out = is_file($work . '/out.jpg') ? $work . '/out.jpg' : (glob($work . '/out-*.jpg')[0] ?? null);
            if (! $p->isSuccessful() || ! $out || filesize($out) === 0) {
                Log::warning('heic-preview: ' . mb_substr(trim($p->getErrorOutput() . ' ' . $p->getOutput()), 0, 300));
                throw MailException::upstream('Не удалось показать фото — скачайте его');
            }
            rename($out, $jpg);

            return $jpg;
        } finally {
            File::deleteDirectory($work);
            $lock->release();
        }
    }
}
