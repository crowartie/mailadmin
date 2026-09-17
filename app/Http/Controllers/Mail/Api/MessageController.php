<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MessageController extends Controller
{
    public function list(Request $request, ImapSession $imap, string $folder): JsonResponse
    {
        // ?filter[]=x и ?q[]=a приходили массивом и роняли запрос ошибкой сервера.
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'filter' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:500'],
        ]);
        $store = new MailStore($imap->client());
        $list = $store->list(
            $folder,
            (int) $request->query('page', 1),
            (string) $request->query('filter', 'all'),
            $request->query('q'),
        );
        $list['folders'] = $store->folders();

        return response()->json($list);
    }

    public function show(Request $request, ImapSession $imap, string $folder, int $uid): JsonResponse
    {
        $store = new MailStore($imap->client());
        $m = $store->message($folder, $uid, ! $request->boolean('peek'));
        // История общения: отправитель прочитанного письма — тоже контакт (кроме своих, рассылок и роботов).
        $from = strtolower((string) ($m['from']['mail'] ?? ''));
        if ($from !== '' && $from !== strtolower($imap->user()) && ! in_array(MailStore::roleOfPath($folder), ['sent', 'drafts', 'spam', 'trash'], true)
            && ! preg_match('/^(no-?reply|noreply|mailer-daemon|postmaster|bounce|notification|do-?not-?reply|cron|root)[@.+-]/i', $from) && ! str_starts_with($folder, MailStore::SHARED_PREFIX)) {
            \App\Models\Webmail\Recent::remember($imap->user(), $from, (string) ($m['from']['name'] ?? ''));
        }

        return response()->json($m);
    }

    /** Цепочка ответов — отдельным запросом после открытия письма. */
    public function thread(ImapSession $imap, string $folder, int $uid): JsonResponse
    {
        return response()->json((new MailStore($imap->client()))->threadOf($folder, $uid));
    }

    public function attachment(Request $request, ImapSession $imap, string $folder, int $uid, int $index): Response
    {
        $a = (new MailStore($imap->client()))->attachment($folder, $uid, $index);
        // Outlook кладёт имя как =?utf-8?B?…?= (бывает в две строки и в koi8-r) — разбираем сами, иначе файл скачается с «сырым» именем.
        $name = MailStore::attachmentName($a);
        $type = $a->getMimeType() ?: 'application/octet-stream';
        // SVG — это не картинка, а документ со скриптами: показанный в домене почты, он получает
        // доступ к сеансу сотрудника. Отдаём его только файлом и обычным текстом.
        $svg = in_array(strtolower($type), ['image/svg+xml', 'image/svg'], true) || preg_match('/\.svgz?$/i', $name);
        $inline = $request->boolean('inline') && ! $svg && (str_starts_with($type, 'image/') || $type === 'application/pdf');

        return response($a->getContent(), 200, [
            'Content-Type' => $svg ? 'text/plain; charset=utf-8' : $type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Предпросмотр офисного документа как PDF (LibreOffice на сервере, только показ — оригинал не меняется). */
    public function attachmentPreview(ImapSession $imap, string $folder, int $uid, int $index): Response
    {
        $pdf = (new MailStore($imap->client()))->attachmentPreviewPdf($folder, $uid, $index);

        return response(file_get_contents($pdf), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Все вложения письма одним архивом (обращение №11). */
    public function attachmentsZip(ImapSession $imap, string $folder, int $uid)
    {
        $zip = (new MailStore($imap->client()))->attachmentsZip($folder, $uid);
        abort_if($zip['count'] === 0, 404, 'У письма нет вложений');

        return response()->download($zip['path'], $zip['name'], [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    /** Исходник письма (.eml) — «Сохранить» и «Показать оригинал». */
    public function raw(ImapSession $imap, string $folder, int $uid): Response
    {
        $raw = (new MailStore($imap->client()))->raw($folder, $uid);

        return response($raw, 200, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => "attachment; filename=\"message-{$uid}.eml\"",
        ]);
    }
}
