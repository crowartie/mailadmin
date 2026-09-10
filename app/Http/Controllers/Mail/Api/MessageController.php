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
        $inline = $request->boolean('inline') && (str_starts_with($type, 'image/') || $type === 'application/pdf');

        return response($a->getContent(), 200, [
            'Content-Type' => $type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
