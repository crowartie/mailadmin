<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Services\Mail\DesktopRelease;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Приложение «Почта» для Windows со своего сервера (desktop/): постоянная ссылка на установщик
 * (/app/pochta-setup.exe) и папка обновлений /app/windows (latest.yml, установщик, .blockmap),
 * из которой установленные приложения обновляются сами. Без входа: ставят до того, как войти.
 */
class DesktopAppController extends Controller
{
    /** Файлы для самообновления: только имена из DesktopRelease::FILE_RE, иначе 404. */
    public function feed(string $file): BinaryFileResponse
    {
        $path = DesktopRelease::path($file);
        abort_unless($path, 404, 'Нет такого файла');
        $type = str_ends_with($file, '.yml') ? 'text/yaml; charset=utf-8'
            : (str_ends_with($file, '.exe') ? 'application/vnd.microsoft.portable-executable' : 'application/octet-stream');

        return response()->file($path, [
            'Content-Type' => $type,
            // latest.yml меняется при каждом выпуске — кэшировать нельзя; установщик с версией в имени — можно.
            'Cache-Control' => str_ends_with($file, '.yml') ? 'no-cache, no-store' : 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Кнопка «Скачать для Windows»: всегда последняя версия под понятным именем. */
    public function download(): BinaryFileResponse
    {
        $r = DesktopRelease::latest();
        abort_unless($r, 404, 'Приложение для Windows ещё не выложено');

        return response()->download((string) DesktopRelease::path($r['file']), $r['file'], [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'Cache-Control' => 'no-cache, no-store',
        ]);
    }
}
