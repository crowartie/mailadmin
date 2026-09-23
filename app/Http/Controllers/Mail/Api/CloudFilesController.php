<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mail\FilesController;
use App\Models\Webmail\CloudFile;
use App\Services\Cloud\LocalFiles;
use App\Services\Cloud\PersonalCloud;
use App\Services\Mail\ImapSession;
use App\Services\Mail\OfficePdf;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Файлы из своего хранилища больших вложений — для вошедшего человека:
 * список своих, продление ссылки, удаление, предпросмотр в почте.
 */
class CloudFilesController extends Controller
{
    /** Мои файлы: что лежит, сколько занято, до какого дня ссылки. */
    public function index(ImapSession $imap): JsonResponse
    {
        $user = $imap->user();
        $s = LocalFiles::settings();
        // Ссылки на файлы облака живут в разделе «Облако» — здесь только большие вложения.
        $files = CloudFile::query()->where('user', $user)->where('source', 'local')->orderByDesc('created_at')->limit(500)->get()
            ->map(fn (CloudFile $f) => $f->toCard($user) + ['created' => $f->created_at?->toIso8601String()])->values();

        return response()->json([
            'files' => $files,
            'used' => LocalFiles::usedBy($user),
            'quota' => (int) $s['user_quota_gb'] * 1073741824,
            'expireDays' => (int) $s['expire_days'],
            'enabled' => LocalFiles::enabled(),
        ]);
    }

    /** Продлить ссылку: ещё на срок из настроек, считая от сегодня. Только свой файл. */
    public function renew(ImapSession $imap, string $token): JsonResponse
    {
        $f = $this->mine($imap, $token);
        (new LocalFiles())->renew($f);

        return response()->json($f->fresh()->toCard($imap->user()));
    }

    /** Удалить свой файл: ссылка перестаёт работать сразу. */
    public function destroy(ImapSession $imap, string $token): JsonResponse
    {
        $f = $this->mine($imap, $token);
        @unlink($f->fullPath());
        $f->delete();

        return response()->json(['ok' => true, 'used' => LocalFiles::usedBy($imap->user())]);
    }

    /** Сведения о файле по ссылке из письма (для карточки вложения). */
    public function show(ImapSession $imap, string $token): JsonResponse
    {
        $f = $this->any($token);

        return response()->json($f->toCard($imap->user()));
    }

    /**
     * Содержимое для предпросмотра в почте (картинка или PDF — как есть).
     * Файл лежит на этом же сервере, поэтому смотреть можно, не скачивая, — если он
     * не тяжелее порога из настроек.
     */
    public function content(ImapSession $imap, string $token): Response
    {
        $f = $this->any($token);
        abort_unless(LocalFiles::previewable($f), 422, 'Файл слишком большой или такого вида, что показать его нельзя — скачайте');
        if ($f->isCloud()) {
            return $this->cloudContent($f);
        }
        if (! is_file($f->fullPath())) {
            abort(404, 'Файла больше нет');
        }
        // Без nginx-редиректа: путь внутри почты, а не с files-хоста; размер ограничен порогом.
        $inline = str_starts_with($f->mime, 'image/') || $f->mime === 'application/pdf';

        return response()->file($f->fullPath(), [
            'Content-Type' => $inline ? $f->mime : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename*=UTF-8\'\'' . rawurlencode($f->name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Офисный документ из хранилища — как PDF. */
    public function preview(ImapSession $imap, string $token): Response
    {
        $f = $this->any($token);
        abort_unless(LocalFiles::previewable($f) && LocalFiles::isOffice($f), 422, 'Этот файл не показываем — скачайте его');
        $pdf = OfficePdf::convertFile($f->fullPath(), $f->name);

        return response()->file($pdf, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="preview.pdf"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600']);
    }

    /** Картинка или PDF из облака сотрудника — nginx берёт из Nextcloud. */
    private function cloudContent(CloudFile $f): Response
    {
        abort_if($f->expired(), 410, 'Срок ссылки истёк');
        $cloud = PersonalCloud::forUser($f->user);
        $path = $cloud->pathOfFile((int) $f->id);
        abort_if($path === null, 404, 'Файла больше нет');

        return response('', 200, $cloud->accel($path) + [
            'Content-Type' => $f->mime,
            'Content-Disposition' => 'inline; filename*=UTF-8\'\'' . rawurlencode($f->name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Свой файл хранилища. Ссылки облака продлевают и удаляют в разделе «Облако». */
    private function mine(ImapSession $imap, string $token): CloudFile
    {
        $f = CloudFile::query()->where('token', $token)->where('source', 'local')->first();
        abort_unless($f && strcasecmp($f->user, $imap->user()) === 0, 404, 'Файл не найден');

        return $f;
    }

    private function any(string $token): CloudFile
    {
        $f = CloudFile::query()->where('token', $token)->first();
        abort_unless($f, 404, 'Файл не найден');

        return $f;
    }
}
