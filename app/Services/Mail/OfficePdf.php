<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Документ → PDF для предпросмотра. Одно место на всех: вложения писем и файлы из хранилища больших вложений.
 *
 * Чем переводим (обращение №64):
 *  - офисные документы, Visio, Publisher, CorelDRAW, Apple Pages/Numbers/Keynote — LibreOffice без окна;
 *  - сканы TIFF (часто многостраничные, с МФУ) — tiff2pdf;
 *  - чертежи DXF — resources/tools/cad2pdf.py (ezdxf → SVG → PDF), DWG — сначала в DXF (LibreDWG dwg2dxf).
 *
 * Готовые PDF лежат в кэше по хэшу содержимого. Каждый вид конвертера работает по одному (свой замок):
 * два soffice разом съедают всю память, а долгий чертёж не должен задерживать документы. Сторонние
 * разборщики файлов (tiff2pdf, dwg2dxf, cad2pdf) — с лимитом памяти и процессорного времени (prlimit).
 */
final class OfficePdf
{
    public const OFFICE = [
        'doc', 'docx', 'docm', 'dot', 'dotx', 'dotm', 'rtf', 'odt', 'ott',
        'xls', 'xlsx', 'xlsm', 'xlsb', 'xltx', 'xltm', 'ods', 'ots', 'csv',
        'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'pot', 'potx', 'odp', 'otp', 'odg',
        'vsd', 'vsdx', 'pub', 'cdr', 'pages', 'numbers', 'key',
    ];
    public const TIFF = ['tif', 'tiff'];
    public const CAD = ['dxf', 'dwg'];
    public const TYPES = [...self::OFFICE, ...self::TIFF, ...self::CAD];

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
        $kind = in_array($ext, self::TIFF, true) ? 'tiff' : (in_array($ext, self::CAD, true) ? 'cad' : 'office');
        $lock = Cache::lock('preview-' . $kind, 200);
        if (! $lock->block(60)) {
            throw MailException::busy('Конвертер занят — попробуйте через минуту');
        }
        $work = $dir . '/tmp-' . bin2hex(random_bytes(6));
        try {
            if (is_file($pdf) && filesize($pdf) > 0) {   // пока ждали, сделал кто-то другой
                return $pdf;
            }
            mkdir($work, 0750, true);
            $src = $work . '/in.' . $ext;
            file_put_contents($src, $content());
            $out = match ($kind) {
                'tiff' => self::tiff($src, $work, $name),
                'cad' => self::cad($src, $ext, $work, $name),
                default => self::office($src, $dir, $work, $name),
            };
            rename($out, $pdf);

            return $pdf;
        } finally {
            File::deleteDirectory($work);
            $lock->release();
        }
    }

    private static function office(string $src, string $dir, string $work, string $name): string
    {
        // Свой профиль в каталоге кэша: у www-data нет домашней папки, без профиля soffice не стартует.
        $cmd = ['soffice', '-env:UserInstallation=file://' . $dir . '/profile', '--headless', '--norestore', '--convert-to', 'pdf', '--outdir', $work, $src];

        return self::run($cmd, $work, $work . '/in.pdf', $name, 120, ['HOME' => $dir], false, 'Не удалось подготовить предпросмотр — скачайте документ');
    }

    /** Скан TIFF (в том числе многостраничный) → PDF без перекодирования картинки. */
    private static function tiff(string $src, string $work, string $name): string
    {
        return self::run(['tiff2pdf', '-o', $work . '/out.pdf', $src], $work, $work . '/out.pdf', $name, 60, [], true, 'Не удалось открыть скан — скачайте файл');
    }

    /** Чертёж: DWG → DXF (LibreDWG), DXF → PDF (ezdxf). */
    private static function cad(string $src, string $ext, string $work, string $name): string
    {
        $dxf = $src;
        if ($ext === 'dwg') {
            if (! is_executable(self::dwg2dxf())) {
                throw MailException::unsupported('Просмотр DWG на этом сервере ещё не настроен — скачайте чертёж');
            }
            $dxf = self::run([self::dwg2dxf(), '-y', '-o', $work . '/in.dxf', $src], $work, $work . '/in.dxf', $name, 90, [], true, 'Не удалось прочитать чертёж DWG — скачайте его и откройте в AutoCAD или бесплатном просмотрщике');
        }
        $tool = resource_path('tools/cad2pdf.py');

        return self::run(['python3', $tool, $dxf, $work . '/out.pdf'], $work, $work . '/out.pdf', $name, 180, ['MPLCONFIGDIR' => $work, 'HOME' => $work], true, 'Не удалось нарисовать чертёж — скачайте его');
    }

    public static function dwg2dxf(): string
    {
        return (string) config('mailadmin.dwg2dxf', '/usr/local/bin/dwg2dxf');
    }

    /**
     * Запустить конвертер и вернуть путь к результату. Сторонние разборщики — в prlimit: не больше 3 ГБ памяти
     * и 150 с процессора, даже если файл подобран так, чтобы их запутать.
     *
     * @param array<int,string> $cmd
     * @param array<string,string> $env
     */
    private static function run(array $cmd, string $cwd, string $out, string $name, int $timeout, array $env, bool $limit, string $fail): string
    {
        if ($limit && is_executable('/usr/bin/prlimit')) {
            $cmd = ['/usr/bin/prlimit', '--as=3221225472', '--cpu=150', '--core=0', '--', ...$cmd];
        }
        $p = new Process($cmd, $cwd, $env ?: null, null, $timeout);
        try {
            $p->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            Log::warning('preview: ' . $name . ': не уложился в ' . $timeout . ' с');
            throw MailException::upstream($fail);
        }
        if (! $p->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
            Log::warning('preview: ' . $name . ': ' . mb_substr(trim($p->getErrorOutput() . ' ' . $p->getOutput()), 0, 500));
            throw MailException::upstream($fail);
        }

        return $out;
    }
}
