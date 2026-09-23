<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Cloud\LocalFiles;
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
            'sort' => ['nullable', 'string', 'in:date,date-asc,from,subject,size'],
            'folders' => ['nullable', 'in:0,1'],
            'scope' => ['nullable', 'string', 'in:folder,all'],
        ]);
        $store = new MailStore($imap->client());
        $q = (string) $request->query('q', '');
        // «Искать везде» имеет смысл только вместе с запросом: пустой поиск по всем папкам —
        // это просто все письма ящика.
        $everywhere = $request->query('scope') === 'all' && trim($q) !== '';
        $list = $everywhere
            // Папку, в которой человек стоит, передаём: с неё поиск и начинается.
            ? $store->searchEverywhere($q, (int) $request->query('page', 1), (string) $request->query('sort', 'date'), $folder)
            : $store->list(
                $folder,
                (int) $request->query('page', 1),
                (string) $request->query('filter', 'all'),
                $request->query('q'),
                (string) $request->query('sort', 'date'),
            );
        // Каждый такой вызов делает STATUS по каждой папке ящика: на тихой перезагрузке
        // счётчики не нужны — их приносит отдельный опрос состояния.
        if ($request->query('folders') !== '0') {
            $list['folders'] = $store->folders();
        }

        return response()->json($list);
    }

    public function show(Request $request, ImapSession $imap, string $folder, int $uid): JsonResponse
    {
        $store = new MailStore($imap->client());
        $markSeen = MailStore::marksSeenOnOpen($folder, \App\Models\Webmail\Setting::for($imap->user()), $request->boolean('peek'));
        $m = $store->message($folder, $uid, $markSeen);
        // Клиент по этому признаку решает, гасить ли «непрочитанное» в строке списка.
        $m['markedSeen'] = $markSeen;
        // Ссылки на своё хранилище в теле письма — карточками: посмотреть, скачать, продлить.
        $m['cloudFiles'] = LocalFiles::cardsIn($m['html'] ?? null, $imap->user());
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
        $store = new MailStore($imap->client());
        $thread = $store->threadOf($folder, $uid);
        // Кроме самих писем отдаём, сколько ещё есть в переписке: раньше остаток
        // просто отсутствовал, и человек не знал, что видит не всю её.
        return response()->json(['messages' => $thread, 'hidden' => $store->threadHidden]);
    }

    public function attachment(Request $request, ImapSession $imap, string $folder, int $uid, int $index): Response
    {
        $a = (new MailStore($imap->client()))->attachment($folder, $uid, $index);
        // Имя уже разобрано: Outlook кладёт его как =?utf-8?B?…?= (бывает в две строки и в koi8-r).
        $name = $a->getName();
        $type = $a->getMimeType() ?: 'application/octet-stream';
        // SVG — это не картинка, а документ со скриптами: показанный в домене почты, он получает
        // доступ к сеансу сотрудника. Отдаём его только файлом и обычным текстом.
        $svg = in_array(strtolower($type), ['image/svg+xml', 'image/svg'], true) || preg_match('/\.svgz?$/i', $name);
        $inline = $request->boolean('inline') && ! $svg && (str_starts_with($type, 'image/') || $type === 'application/pdf');

        return response($a->getContent(), 200, [
            'Content-Type' => $svg ? 'text/plain; charset=utf-8' : $type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            // Встроенные картинки (логотипы в подписях, снимки в теле) одинаковы при каждом открытии:
            // по журналу действий одно письмо тянуло их по 50 штук за секунду. Сутки в кэше браузера.
            'Cache-Control' => $inline ? 'private, max-age=86400' : 'private, no-store',
        ]);
    }

    /** Письмо, приложенное к письму: показываем его в почте как обычное письмо (обращение №39). */
    public function attachedMessage(ImapSession $imap, string $folder, int $uid, int $index): JsonResponse
    {
        return response()->json((new MailStore($imap->client()))->attachedMessage($folder, $uid, $index));
    }

    /** Вложение изнутри приложенного письма. */
    public function attachedPart(Request $request, ImapSession $imap, string $folder, int $uid, int $index, int $sub): Response
    {
        $a = (new MailStore($imap->client()))->attachedPart($folder, $uid, $index, $sub);
        $type = $a->getMimeType() ?: 'application/octet-stream';
        $name = $a->getName();
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

    /**
     * Все файлы из облака, на которые ссылается письмо, одним архивом. Только свои файлы
     * (лежат в хранилище этого сервера) и только с живой ссылкой — как и карточки в письме.
     */
    public function cloudZip(ImapSession $imap, string $folder, int $uid)
    {
        $m = (new MailStore($imap->client()))->message($folder, $uid, false);
        $cards = LocalFiles::cardsIn($m['html'] ?? null, $imap->user());
        $tokens = array_column(array_filter($cards, fn ($c) => empty($c['expired'])), 'token');
        // Файлы из облака сотрудника (source = nc) в архив не берём: они бывают по нескольку гигабайт.
        $files = \App\Models\Webmail\CloudFile::query()->whereIn('token', $tokens)->where('source', 'local')->get()
            ->filter(fn ($f) => is_file($f->fullPath()));
        abort_if($files->isEmpty(), 404, 'У письма нет файлов из облака с живой ссылкой');

        $tmp = tempnam(sys_get_temp_dir(), 'cloud');
        $zip = new \ZipArchive();
        abort_if($zip->open($tmp, \ZipArchive::OVERWRITE) !== true, 500, 'Не удалось создать архив');
        $used = [];
        foreach ($files as $f) {
            $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1f]+#', '_', (string) $f->name) ?: 'файл';
            if (isset($used[$name])) {
                $name = preg_replace('/(\.[^.]+)?$/', '-' . (++$used[$name]) . '$1', $name, 1);
            } else {
                $used[$name] = 1;
            }
            $zip->addFile($f->fullPath(), $name);
        }
        $zip->close();
        $subject = trim(preg_replace('#[\\\\/:*?"<>|\x00-\x1f]+#', ' ', (string) ($m['subject'] ?? '')));

        return response()->download($tmp, 'файлы' . ($subject !== '' ? ' — ' . mb_substr($subject, 0, 60) : '') . '.zip', [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    /** Исходник письма (.eml) — «Сохранить» и «Показать оригинал». */
    public function raw(ImapSession $imap, Request $request, string $folder, int $uid): Response
    {
        $raw = (new MailStore($imap->client()))->raw($folder, $uid);

        // «Показать оригинал» (inline=1) — показать заголовки в окне браузера; без него это
        // «Скачать .eml». Раньше оба пункта вели на один адрес, и «показать» открывало
        // пустую вкладку и клало в загрузки второй файл.
        if ($request->boolean('inline')) {
            return response($raw, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'inline',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response($raw, 200, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => "attachment; filename=\"message-{$uid}.eml\"",
        ]);
    }
}
