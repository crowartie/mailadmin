<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Webmail\CloudFile;
use App\Services\Cloud\LocalFiles;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Выдача файла по ссылке из письма: https://files.<домен>/<токен>/<имя>.
 *
 * Входа нет — ссылку читает внешний получатель. Отдаём только на скачивание и только
 * через nginx (X-Accel-Redirect): PHP не держит в памяти полгигабайта, а загруженный
 * HTML никогда не выполнится в браузере как страница.
 */
class FilesController extends Controller
{
    public function download(Request $request, string $token, ?string $name = null): Response
    {
        $f = CloudFile::query()->where('token', $token)->first();
        if (! $f || ! is_file($f->fullPath())) {
            return response()->view('mail.file-gone', ['reason' => 'missing', 'file' => null], 404);
        }
        if ($f->expired()) {
            return response()->view('mail.file-gone', ['reason' => 'expired', 'file' => $f], 410);
        }
        if ($request->isMethod('HEAD')) {
            return response('', 200, ['Content-Length' => (string) $f->size, 'Content-Type' => 'application/octet-stream']);
        }
        $f->increment('downloads', 1, ['last_download_at' => now()]);

        return self::serve($f, 'attachment');
    }

    /**
     * Ответ с файлом через nginx. Для предпросмотра внутри почты (после входа) можно
     * отдать inline — но только картинки и PDF, остальное всегда вложением.
     */
    public static function serve(CloudFile $f, string $disposition = 'attachment'): Response
    {
        $inlineOk = str_starts_with($f->mime, 'image/') || $f->mime === 'application/pdf';
        $disp = $disposition === 'inline' && $inlineOk ? 'inline' : 'attachment';
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $f->name) ?: 'file';

        return response('', 200, [
            'X-Accel-Redirect' => '/_files/' . $f->path,
            'Content-Type' => $disp === 'inline' ? $f->mime : 'application/octet-stream',
            'Content-Disposition' => $disp . '; filename="' . str_replace('"', '', $ascii) . '"; filename*=UTF-8\'\'' . rawurlencode($f->name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, max-age=0, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** Отдать файл без nginx — для тестовой копии и на случай, если внутренний location не настроен. */
    public static function stream(CloudFile $f): Response
    {
        return response()->file($f->fullPath(), ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename*=UTF-8\'\'' . rawurlencode($f->name)]);
    }

    /** Права каталога хранилища: проверка при выкладке. */
    public static function ready(): bool
    {
        $root = LocalFiles::root();

        return is_dir($root) && is_writable($root);
    }
}
