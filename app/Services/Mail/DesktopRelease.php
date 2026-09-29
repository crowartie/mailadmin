<?php

namespace App\Services\Mail;

/**
 * Текущий выпуск приложения «Почта» для Windows (desktop/): установщик, его .blockmap и latest.yml
 * лежат на самом сервере (storage/app/private/desktop), их кладёт скрипт выкладки. Отсюда же
 * установленные приложения скачивают обновления (electron-updater, provider generic → /app/windows).
 */
class DesktopRelease
{
    /** Имена файлов, которые можно отдавать: только то, что делает electron-builder, — никаких путей. */
    public const FILE_RE = '/^(latest\.yml|Pochta-Setup-\d+\.\d+\.\d+\.exe(\.blockmap)?)$/';

    public static function dir(): string
    {
        return (string) config('mailadmin.desktop_release_dir', storage_path('app/private/desktop'));
    }

    /** Путь к файлу выпуска или null, если имени нет в списке разрешённых или файла нет. */
    public static function path(string $name): ?string
    {
        if (! preg_match(self::FILE_RE, $name)) {
            return null;
        }
        $p = self::dir() . '/' . $name;

        return is_file($p) ? $p : null;
    }

    /**
     * Описание выпуска из latest.yml: версия, файл установщика, размер, дата, «что нового» (notes.txt).
     * @return array{version:string,file:string,size:int,date:string,notes:string}|null
     */
    public static function latest(): ?array
    {
        $yml = self::path('latest.yml');
        if (! $yml) {
            return null;
        }
        $t = (string) file_get_contents($yml);
        $get = fn (string $k) => preg_match('/^' . $k . ':\s*[\'"]?([^\'"\r\n]+)[\'"]?\s*$/m', $t, $m) ? trim($m[1]) : '';
        $version = $get('version');
        $file = $get('path');
        $exe = $file !== '' ? self::path($file) : null;
        if ($version === '' || ! $exe) {
            return null;   // описание есть, а установщика нет — выпуск не готов, показывать нечего
        }
        $notes = is_file(self::dir() . '/notes.txt') ? trim((string) file_get_contents(self::dir() . '/notes.txt')) : '';
        $date = substr($get('releaseDate'), 0, 10) ?: date('Y-m-d', (int) filemtime($exe));

        return ['version' => $version, 'file' => $file, 'size' => (int) filesize($exe), 'date' => $date, 'notes' => $notes];
    }
}
