<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Cloud\PersonalCloud;
use App\Services\Cloud\PersonalPath;
use App\Services\Mail\ImapSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Личное облако сотрудника в веб-почте (раздел «Облако»).
 *
 * Все пути в запросах — относительно папки сотрудника; PersonalCloud проверяет каждый
 * через PersonalPath, поэтому контроллер ничего сам с путями не делает.
 */
class PersonalCloudController extends Controller
{
    private function cloud(ImapSession $imap): PersonalCloud
    {
        return PersonalCloud::forUser($imap->user());
    }

    /** Содержимое папки, ссылки на файлы в ней, занятое место. */
    public function list(Request $request, ImapSession $imap): JsonResponse
    {
        $c = $this->cloud($imap);
        $path = PersonalPath::clean((string) $request->query('path', ''));
        $items = $c->list($path);
        $links = $c->links();
        foreach ($items as &$it) {
            $it['link'] = $links[$it['path']] ?? null;
        }
        unset($it);

        return response()->json(['path' => $path, 'items' => $items, 'used' => $c->usage(), 'quota' => $c->quota()]);
    }

    /** Только папки — для дерева слева и выбора, куда перенести. */
    public function folders(Request $request, ImapSession $imap): JsonResponse
    {
        $items = $this->cloud($imap)->list((string) $request->query('path', ''));

        return response()->json(['items' => array_values(array_filter($items, fn ($i) => $i['dir']))]);
    }

    public function recent(ImapSession $imap): JsonResponse
    {
        $c = $this->cloud($imap);
        $links = $c->links();
        $items = array_map(fn ($i) => $i + ['link' => $links[$i['path']] ?? null], $c->recent());

        return response()->json(['items' => $items, 'used' => $c->usage(), 'quota' => $c->quota()]);
    }

    public function withLinks(ImapSession $imap): JsonResponse
    {
        $c = $this->cloud($imap);
        $items = [];
        foreach ($c->links() as $path => $l) {
            $items[] = ['name' => PersonalPath::base($path), 'path' => $path, 'dir' => false, 'size' => null, 'modified' => null, 'type' => '', 'link' => $l];
        }

        return response()->json(['items' => $items, 'used' => $c->usage(), 'quota' => $c->quota()]);
    }

    public function mkdir(Request $request, ImapSession $imap): JsonResponse
    {
        $d = $request->validate(['path' => ['nullable', 'string', 'max:2000'], 'name' => ['required', 'string', 'max:250']]);

        return response()->json(['path' => $this->cloud($imap)->mkdir((string) ($d['path'] ?? ''), $d['name'])]);
    }

    public function rename(Request $request, ImapSession $imap): JsonResponse
    {
        $d = $request->validate(['path' => ['required', 'string', 'max:2000'], 'name' => ['required', 'string', 'max:250']]);

        return response()->json(['path' => $this->cloud($imap)->rename($d['path'], $d['name'])]);
    }

    public function move(Request $request, ImapSession $imap): JsonResponse
    {
        $d = $request->validate(['paths' => ['required', 'array', 'max:100'], 'paths.*' => ['string', 'max:2000'], 'to' => ['nullable', 'string', 'max:2000']]);
        $c = $this->cloud($imap);
        $done = [];
        foreach ($d['paths'] as $p) {
            $done[] = $c->move($p, (string) ($d['to'] ?? ''));
        }

        return response()->json(['paths' => $done]);
    }

    public function delete(Request $request, ImapSession $imap): JsonResponse
    {
        $d = $request->validate(['paths' => ['required', 'array', 'max:100'], 'paths.*' => ['string', 'max:2000']]);
        $c = $this->cloud($imap);
        foreach ($d['paths'] as $p) {
            $c->delete($p);
        }

        return response()->json(['deleted' => count($d['paths'])]);
    }

    public function trash(ImapSession $imap): JsonResponse
    {
        $c = $this->cloud($imap);

        return response()->json(['items' => $c->trash(), 'used' => $c->usage(), 'quota' => $c->quota()]);
    }

    public function restore(ImapSession $imap, int $id): JsonResponse
    {
        return response()->json(['path' => $this->cloud($imap)->restore($id)]);
    }

    public function purge(ImapSession $imap, int $id): JsonResponse
    {
        $this->cloud($imap)->purge($id);

        return response()->json(['ok' => true]);
    }

    public function emptyTrash(ImapSession $imap): JsonResponse
    {
        return response()->json(['purged' => $this->cloud($imap)->emptyTrash()]);
    }

    // ── загрузка ──

    public function uploadStart(Request $request, ImapSession $imap): JsonResponse
    {
        $d = $request->validate(['path' => ['nullable', 'string', 'max:2000'], 'name' => ['required', 'string', 'max:500'], 'size' => ['required', 'integer', 'min:0', 'max:107374182400']]);

        return response()->json($this->cloud($imap)->startUpload((string) ($d['path'] ?? ''), $d['name'], (int) $d['size']));
    }

    public function uploadStatus(ImapSession $imap, string $id): JsonResponse
    {
        return response()->json($this->cloud($imap)->uploadStatus($id));
    }

    public function uploadChunk(Request $request, ImapSession $imap, string $id, int $n): JsonResponse
    {
        $this->cloud($imap)->putChunk($id, $n, $request->getContent());

        return response()->json(['ok' => true, 'n' => $n]);
    }

    public function uploadFinish(ImapSession $imap, string $id): JsonResponse
    {
        return response()->json(['item' => $this->cloud($imap)->finishUpload($id)]);
    }

    public function uploadAbort(ImapSession $imap, string $id): JsonResponse
    {
        $this->cloud($imap)->abortUpload($id);

        return response()->json(['ok' => true]);
    }

    // ── ссылки ──

    public function link(Request $request, ImapSession $imap): JsonResponse
    {
        $d = $request->validate(['path' => ['required', 'string', 'max:2000'], 'days' => ['required', 'integer', 'min:0', 'max:3650'], 'password' => ['nullable', 'boolean']]);

        return response()->json(['link' => $this->cloud($imap)->link($d['path'], (int) $d['days'], array_key_exists('password', $d) ? $d['password'] : null)]);
    }

    public function unlink(Request $request, ImapSession $imap): JsonResponse
    {
        $d = $request->validate(['path' => ['required', 'string', 'max:2000']]);
        $this->cloud($imap)->unlink($d['path']);

        return response()->json(['ok' => true]);
    }

    /** Приложить к письму: ссылки на файлы и готовый блок для текста письма. */
    public function attach(Request $request, ImapSession $imap): JsonResponse
    {
        $d = $request->validate(['paths' => ['required', 'array', 'min:1', 'max:20'], 'paths.*' => ['string', 'max:2000']]);
        $links = $this->cloud($imap)->attach($d['paths']);

        return response()->json(['links' => $links, 'html' => \App\Services\Mail\MailBuilder::linksBlock($links)]);
    }

    // ── скачивание и просмотр ──

    public function file(Request $request, ImapSession $imap): Response
    {
        $path = PersonalPath::clean((string) $request->query('path', ''));
        if ($path === '') {
            abort(404);
        }
        $c = $this->cloud($imap);
        $st = $c->stat($path);
        if (! $st || $st['dir']) {
            abort(404, 'Файла нет');
        }
        $name = $st['name'];
        // Показать в браузере можно только то, что не исполняется в домене почты.
        $safe = (bool) preg_match('~^(image/(png|jpe?g|gif|webp)|video/|audio/|application/pdf$)~', $st['type']);
        $inline = $request->boolean('inline') && $safe;

        return response('', 200, $c->accel($path) + [
            'Content-Type' => $safe ? $st['type'] : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
