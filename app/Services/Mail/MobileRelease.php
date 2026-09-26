<?php

namespace App\Services\Mail;

/**
 * Текущий выпуск приложения «Почта» для Android: файл и его описание лежат на самом сервере
 * (storage/app/private/mobile: pochta.apk и latest.json), без GitHub и магазинов приложений.
 * Кладёт их администратор скриптом выкладки; сервер только отдаёт.
 */
class MobileRelease
{
    public static function dir(): string
    {
        return (string) config('mailadmin.mobile_release_dir', storage_path('app/private/mobile'));
    }

    public static function apkPath(): ?string
    {
        $p = self::dir() . '/pochta.apk';

        return is_file($p) ? $p : null;
    }

    /**
     * Описание выпуска: версия, номер сборки, размер, SHA-256 файла (приложение сверяет его
     * перед установкой), что нового, дата. null — выпуска на сервере нет.
     *
     * @return array{version:string,code:int,size:int,sha256:string,notes:string,date:string}|null
     */
    public static function latest(): ?array
    {
        $apk = self::apkPath();
        $json = self::dir() . '/latest.json';
        if (! $apk || ! is_file($json)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($json), true);
        if (! is_array($d) || empty($d['version'])) {
            return null;
        }

        return [
            'version' => (string) $d['version'],
            'code' => (int) ($d['code'] ?? 0),
            'size' => (int) filesize($apk),
            'sha256' => (string) ($d['sha256'] ?? ''),
            'notes' => (string) ($d['notes'] ?? ''),
            'date' => (string) ($d['date'] ?? date('Y-m-d', (int) filemtime($apk))),
        ];
    }
}
