<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Cloud\LocalFiles;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use App\Services\Mail\SharedReads;
use App\Services\Mail\SharedReplies;
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
            // Прокрутка: кусок списка с любого места.
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
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
        $offset = $request->query('offset') !== null ? (int) $request->query('offset') : null;
        $limit = $request->query('limit') !== null ? (int) $request->query('limit') : null;
        $list = $everywhere
            // Папку, в которой человек стоит, передаём: с неё поиск и начинается.
            ? $store->searchEverywhere($q, (int) $request->query('page', 1), (string) $request->query('sort', 'date'), $folder, $offset, $limit)
            : $store->list(
                $folder,
                (int) $request->query('page', 1),
                (string) $request->query('filter', 'all'),
                $request->query('q'),
                (string) $request->query('sort', 'date'),
                $offset,
                $limit,
            );
        // Каждый такой вызов делает STATUS по каждой папке ящика: на тихой перезагрузке
        // счётчики не нужны — их приносит отдельный опрос состояния.
        if ($request->query('folders') !== '0') {
            $list['folders'] = $store->folders();
        }
        // Общая папка: к каждой строке — кто из коллег уже прочитал (кружки справа в списке).
        if (! $everywhere) {
            $list = SharedReplies::attach(SharedReads::attach($list, $folder), $folder);
        }

        return response()->json($list);
    }

    /** Переход к дате: с какого места списка начинаются письма этого дня. */
    public function listAt(Request $request, ImapSession $imap, string $folder): JsonResponse
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'filter' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:500'],
            'sort' => ['nullable', 'string', 'in:date,date-asc'],
        ]);
        $store = new MailStore($imap->client());

        return response()->json(['offset' => $store->offsetForDate($folder, (string) ($d['filter'] ?? 'all'), $d['q'] ?? null, (string) ($d['sort'] ?? 'date'), $d['date'])]);
    }

    public function show(Request $request, ImapSession $imap, string $folder, int $uid): JsonResponse
    {
        $store = new MailStore($imap->client());
        $markSeen = MailStore::marksSeenOnOpen($folder, \App\Models\Webmail\Setting::for($imap->user()), $request->boolean('peek'));
        $m = $store->message($folder, $uid, $markSeen);
        // Клиент по этому признаку решает, гасить ли «непрочитанное» в строке списка.
        $m['markedSeen'] = $markSeen;
        // Общая папка: запоминаем, что этот человек прочитал, и отдаём «кто прочитал / кто ещё нет».
        if (($owner = SharedReads::ownerOf($folder)) !== null) {
            if ($markSeen) {
                SharedReads::record($owner, $m['messageId'] ?? null, $imap->user());
            }
            $m['readers'] = SharedReads::forMessages($owner, [$m['messageId'] ?? null])[SharedReads::key($m['messageId'] ?? null)] ?? [];
            $m['notRead'] = SharedReads::notRead($owner, SharedReads::ownerPath($folder), $m['readers']);
            // Кто ответил и каким письмом — щелчок открывает ответ (обращение №57).
            $m['replies'] = SharedReplies::forMessages($owner, [$m['messageId'] ?? null])[SharedReads::key($m['messageId'] ?? null)] ?? [];
        }
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
        // доступ к сеансу сотрудника. Скачивается — обычным текстом; для просмотра — только картинкой (ниже).
        $svg = in_array(strtolower($type), ['image/svg+xml', 'image/svg'], true) || preg_match('/\.svgz?$/i', $name);
        // Видео и звук — для встроенного плеера просмотрщика (обращение №64): почтовые программы часто
        // присылают их как application/octet-stream, тип берём по расширению.
        $media = self::mediaType($name);
        if ($media && ! str_starts_with($type, 'video/') && ! str_starts_with($type, 'audio/')) {
            $type = $media;
        }
        $inline = $request->boolean('inline') && (str_starts_with($type, 'image/') || $type === 'application/pdf' || $media !== null);
        $headers = [];
        if ($svg && $inline) {
            // В <img> скрипты SVG не выполняются, а открытый отдельной вкладкой файл заперт политикой sandbox:
            // без скриптов и без доступа к сеансу почты.
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";
        }

        return response($a->getContent(), 200, $headers + [
            'Content-Type' => $svg ? ($inline ? 'image/svg+xml' : 'text/plain; charset=utf-8') : $type,
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

    /** Ответ коллеги на письмо из общей папки — показываем как письмо-вложение (обращение №57). */
    public function sharedReply(ImapSession $imap, int $id): JsonResponse
    {
        return response()->json(SharedReplies::show(SharedReplies::find($id, $imap->user())));
    }

    /** Вложение изнутри такого ответа. */
    public function sharedReplyPart(Request $request, ImapSession $imap, int $id, int $sub): Response
    {
        $a = \App\Services\Mail\AttachedMessage::part(SharedReplies::raw(SharedReplies::find($id, $imap->user())), $sub);

        return $this->partResponse($request, $a);
    }

    /** Вложение изнутри приложенного письма. */
    public function attachedPart(Request $request, ImapSession $imap, string $folder, int $uid, int $index, int $sub): Response
    {
        return $this->partResponse($request, (new MailStore($imap->client()))->attachedPart($folder, $uid, $index, $sub));
    }

    /** Файл изнутри письма-вложения: картинки и PDF можно показать в окне, остальное — скачать; SVG — только текстом. */
    private function partResponse(Request $request, \App\Services\Mail\MailPart $a): Response
    {
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

    /** Тип видео или звука по расширению — для встроенного плеера. */
    public static function mediaType(string $name): ?string
    {
        return [
            'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'ogv' => 'video/ogg',
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg', 'opus' => 'audio/ogg', 'flac' => 'audio/flac',
        ][strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
    }

    /** Фото HEIC (iPhone) из вложения — в JPEG (ImagePreview). */
    public function attachmentImage(ImapSession $imap, string $folder, int $uid, int $index): Response
    {
        $a = (new MailStore($imap->client()))->attachment($folder, $uid, $index);
        $jpg = \App\Services\Mail\ImagePreview::fromContent((string) $a->getContent(), (string) $a->getName(), (string) $a->getMimeType());

        return response(file_get_contents($jpg), 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="preview.jpg"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** Таблица из вложения — таблицей: листы, ячейки, оформление (SheetPreview). */
    public function attachmentSheet(ImapSession $imap, string $folder, int $uid, int $index): Response
    {
        $a = (new MailStore($imap->client()))->attachment($folder, $uid, $index);
        $json = \App\Services\Mail\SheetPreview::fromContent((string) $a->getContent(), (string) $a->getName());

        return response(file_get_contents($json), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Все вложения письма одним архивом (обращение №11). */
    public function attachmentsZip(ImapSession $imap, string $folder, int $uid)
    {
        $zip = (new MailStore($imap->client()))->attachmentsZip($folder, $uid);
        \App\Support\TempFiles::deleteAfterResponse($zip['path']);   // и при отказе ниже
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
    /** Сколько файлов из облака сотрудника можно собрать в один ZIP: их качаем из Nextcloud по сети. */
    public const ZIP_CLOUD_MAX = 2 * 1073741824;

    public function cloudZip(ImapSession $imap, string $folder, int $uid)
    {
        $m = (new MailStore($imap->client()))->message($folder, $uid, false);
        $cards = LocalFiles::cardsIn($m['html'] ?? null, $imap->user());
        $tokens = array_column(array_filter($cards, fn ($c) => empty($c['expired'])), 'token');
        // Порядок — как в письме.
        $rows = \App\Models\Webmail\CloudFile::query()->whereIn('token', $tokens)->get()->keyBy('token');
        $files = collect($tokens)->map(fn ($t) => $rows[$t] ?? null)->filter()
            ->filter(fn ($f) => $f->isCloud() || is_file($f->fullPath()))->values();
        abort_if($files->isEmpty(), 404, 'У письма нет файлов из облака с живой ссылкой');
        $remote = (int) $files->filter(fn ($f) => $f->isCloud())->sum('size');
        abort_if($remote > self::ZIP_CLOUD_MAX, 422, 'Файлы из облака весят ' . \App\Support\Format::size($remote)
            . ' — одним архивом больше ' . \App\Support\Format::size(self::ZIP_CLOUD_MAX) . ' не собираем, скачайте их по одному');
        set_time_limit(600);

        $tmp = tempnam(sys_get_temp_dir(), 'cloud');
        \App\Support\TempFiles::deleteAfterResponse($tmp);   // deleteFileAfterSend под Octane не срабатывает
        $parts = [];   // временные копии файлов из облака — убрать после сборки
        $zip = new \ZipArchive();
        abort_if($zip->open($tmp, \ZipArchive::OVERWRITE) !== true, 500, 'Не удалось создать архив');
        $used = [];
        try {
            foreach ($files as $f) {
                $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1f]+#', '_', (string) $f->name) ?: 'файл';
                if (isset($used[$name])) {
                    $name = preg_replace('/(\.[^.]+)?$/', '-' . (++$used[$name]) . '$1', $name, 1);
                } else {
                    $used[$name] = 1;
                }
                if ($f->isCloud()) {
                    $cloud = \App\Services\Cloud\PersonalCloud::forUser($f->user);
                    $path = $cloud->pathOfFile((int) $f->id);
                    abort_if($path === null, 404, 'Файла «' . $f->name . '» уже нет в облаке');
                    $part = tempnam(sys_get_temp_dir(), 'ncz');
                    $parts[] = $part;
                    $cloud->fetchTo($path, $part);
                    $zip->addFile($part, $name);
                    // Видео и фото уже сжаты: упаковка без сжатия быстрее и почти не больше.
                    $zip->setCompressionName($name, \ZipArchive::CM_STORE);
                } else {
                    $zip->addFile($f->fullPath(), $name);
                }
            }
            $zip->close();
        } finally {
            foreach ($parts as $part) {
                @unlink($part);
            }
        }
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
