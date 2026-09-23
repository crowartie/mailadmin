<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Webmail\CloudFile;
use App\Services\Cloud\LocalFiles;
use App\Services\Cloud\PersonalCloud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Выдача файла по ссылке из письма: https://files.<домен>/<токен>/<имя>.
 *
 * Входа нет — ссылку читает внешний получатель. Отдаём только на скачивание и только
 * через nginx (X-Accel-Redirect): PHP не держит в памяти полгигабайта, а загруженный
 * HTML никогда не выполнится в браузере как страница.
 *
 * Так же отдаются ссылки на файлы из облака сотрудника (source = nc): получатель видит
 * тот же files-хост, а nginx берёт файл из Nextcloud (location /_nccloud/). Ссылку облака
 * можно закрыть паролем — тогда сначала страница с полем пароля.
 */
class FilesController extends Controller
{
    public function download(Request $request, string $token, ?string $name = null): Response
    {
        $f = CloudFile::query()->where('token', $token)->first();
        if ($f && $f->isCloud()) {
            return $this->fromCloud($request, $f);
        }
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

    /** Файл из облака сотрудника: проверка ссылки и пароля, сам файл — nginx из Nextcloud. */
    private function fromCloud(Request $request, CloudFile $f): Response
    {
        if ($f->expired()) {
            return response()->view('mail.file-gone', ['reason' => 'expired', 'file' => $f], 410);
        }
        try {
            $cloud = PersonalCloud::forUser($f->user);
            $path = $cloud->pathOfFile((int) $f->id);
            $st = $path !== null ? $cloud->stat($path) : null;
        } catch (\Throwable $e) {
            Log::warning('files: облако не ответило по ссылке ' . $f->token . ': ' . $e->getMessage());

            return response()->view('mail.file-gone', ['reason' => 'unavailable', 'file' => null], 503);
        }
        if (! $st || $st['dir']) {
            return response()->view('mail.file-gone', ['reason' => 'missing', 'file' => null], 404);
        }
        if ($f->password && ! $this->unlocked($request, $f)) {
            return $this->askPassword($request, $f);
        }
        if ($request->isMethod('HEAD')) {
            return response('', 200, ['Content-Length' => (string) $st['size'], 'Content-Type' => 'application/octet-stream']);
        }
        // Докачка и перемотка приходят кусками — скачиванием считаем только запрос с начала файла.
        $range = (string) $request->header('Range', '');
        if ($range === '' || str_starts_with($range, 'bytes=0-')) {
            $f->increment('downloads', 1, ['last_download_at' => now()]);
        }
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $st['name']) ?: 'file';

        return response('', 200, $cloud->accel($path) + [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $ascii) . '"; filename*=UTF-8\'\'' . rawurlencode($st['name']),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Пароль ссылки облака. Верный — ставим cookie на полдня (докачка и повторное скачивание
     * без повторного ввода) и отправляем обратно на ту же ссылку уже GET-ом.
     */
    private function askPassword(Request $request, CloudFile $f): Response
    {
        $error = '';
        if ($request->isMethod('POST')) {
            $key = 'files-pwd:' . $f->id . ':' . $request->ip();
            if (RateLimiter::tooManyAttempts($key, 10)) {
                $error = 'Слишком много попыток. Подождите ' . max(1, (int) ceil(RateLimiter::availableIn($key) / 60)) . ' мин.';
            } elseif (password_verify(trim((string) $request->input('password', '')), (string) $f->password)) {
                RateLimiter::clear($key);

                return redirect()->to($f->url(), 303)->withCookie(cookie('fp' . $f->id, $this->pass($f), 720, '/', null, true, true, false, 'lax'));
            } else {
                RateLimiter::hit($key, 600);
                $error = 'Пароль не подошёл';
            }
        }

        return response()->view('mail.file-password', ['file' => $f, 'error' => $error], $error !== '' ? 403 : 401, ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store']);
    }

    private function unlocked(Request $request, CloudFile $f): bool
    {
        return $request->isMethod('GET') || $request->isMethod('HEAD')
            ? hash_equals($this->pass($f), (string) $request->cookie('fp' . $f->id, ''))
            : false;
    }

    /** Отметка «пароль введён» — зависит от хэша: сменили пароль — старые отметки не действуют. */
    private function pass(CloudFile $f): string
    {
        return hash_hmac('sha256', $f->token . '|' . $f->password, (string) config('app.key'));
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
