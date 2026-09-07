<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Services\Mail\ImapSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Каркас веб-почты: папки, список писем, чтение. Без отправки, без кэша —
 * ровно столько, чтобы увидеть живую почту в нашей оболочке.
 */
class InboxController extends Controller
{
    private const PAGE = 40;

    public function index(Request $request, ImapSession $imap, string $folder = 'INBOX'): Response
    {
        $client = $imap->client();
        $page = max(1, (int) $request->query('page', 1));

        $folders = collect($client->getFolders(false))->map(fn (Folder $f) => [
            'path' => $f->path,
            'name' => $this->folderTitle($f),
            'unread' => $this->safeUnread($f),
        ])->sortBy(fn ($f) => $this->folderOrder($f['path']))->values()->all();

        $current = $client->getFolder($folder);
        $status = $current->examine();
        $total = (int) ($status['exists'] ?? 0);

        // Последние письма — с конца ящика, по номерам сообщений.
        $to = $total - ($page - 1) * self::PAGE;
        $from = max(1, $to - self::PAGE + 1);
        $messages = [];

        if ($to >= 1) {
            $result = $current->query()->all()->setFetchBody(false)->setFetchFlags(true)->setFetchOrder('desc')
                ->limit(self::PAGE, $page)->get();

            foreach ($result as $message) {
                $messages[] = $this->summary($message);
            }
        }

        return Inertia::render('Mail/Inbox', [
            'user' => $imap->user(),
            'folders' => $folders,
            'folder' => $folder,
            'messages' => $messages,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PAGE),
        ]);
    }

    public function show(ImapSession $imap, string $folder, int $uid): JsonResponse
    {
        $message = $imap->client()->getFolder($folder)->query()->getMessageByUid($uid);
        abort_unless($message, 404);

        $html = $this->fixCharset($message->hasHTMLBody() ? $message->getHTMLBody() : null);
        $text = $this->fixCharset($message->hasTextBody() ? $message->getTextBody() : null);

        $attachments = [];
        foreach ($message->getAttachments() as $a) {
            $attachments[] = ['name' => $a->getName(), 'size' => $a->getSize(), 'type' => $a->getMimeType()];
        }

        if (! $message->getFlags()->has('seen')) {
            $message->setFlag('Seen');
        }

        return response()->json($this->summary($message) + [
            'html' => $html ? $this->sanitize($html) : null,
            'text' => $text,
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
            'attachments' => $attachments,
        ]);
    }

    /** @return array<string,mixed> */
    private function summary(Message $message): array
    {
        $from = $message->getFrom()->first();
        $date = $message->getDate()->first();

        $subject = trim((string) $this->fixCharset((string) ($message->getSubject()->first() ?? '')));

        return [
            'uid' => $message->getUid(),
            'subject' => $subject !== '' ? $subject : '(без темы)',
            'from' => $from ? ['name' => $from->personal ?: $from->mail, 'mail' => $from->mail] : ['name' => '—', 'mail' => ''],
            'date' => $date ? $date->toIso8601String() : null,
            'seen' => $message->getFlags()->has('seen'),
            'flagged' => $message->getFlags()->has('flagged'),
            'answered' => $message->getFlags()->has('answered'),
            'hasAttachments' => $message->hasAttachments(),
            'size' => $message->getSize(),
        ];
    }

    private function addresses($attribute): array
    {
        $out = [];
        foreach ($attribute ?? [] as $a) {
            $out[] = ['name' => $a->personal ?: $a->mail, 'mail' => $a->mail];
        }

        return $out;
    }

    /**
     * Письмо без объявленной кодировки библиотека «угадывает» как ISO-8859-1/2 и перекодирует
     * в UTF-8: кириллица превращается в «Ð¿Ñ€Ð¸Ð²ÐµÑ‚» или «ĐŃĐ¸Đ˛ĐľŃ». Узнаём такой случай
     * и откатываем через ту же однобайтовую таблицу. Если кириллица уже есть — не трогаем.
     */
    private function fixCharset(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return $s;
        }

        if (! mb_check_encoding($s, 'UTF-8')) {
            // Сырые байты: пробуем как UTF-8, потом как windows-1251.
            $utf = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            if (mb_check_encoding($utf, 'UTF-8') && preg_match('/[\x{0400}-\x{04FF}]/u', $utf)) {
                return $utf;
            }

            return mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
        }

        if (preg_match('/[\x{0400}-\x{04FF}]/u', $s) || ! preg_match('/[\x{0080}-\x{024F}]{2}/u', $s)) {
            return $s;
        }

        foreach (['ISO-8859-2', 'ISO-8859-1', 'Windows-1252', 'ISO-8859-4', 'ISO-8859-10'] as $table) {
            $back = @mb_convert_encoding($s, $table, 'UTF-8');
            if (is_string($back) && mb_check_encoding($back, 'UTF-8') && preg_match('/[\x{0400}-\x{04FF}]/u', $back)) {
                return $back;
            }
        }

        return $s;
    }

    /**
     * Письмо — чужой HTML. Режем скрипты, формы, внешние ресурсы и стили,
     * которые могут вылезти за пределы окна чтения.
     */
    private function sanitize(string $html): string
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.ForbiddenElements', ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'style', 'link', 'meta']);
        $config->set('HTML.ForbiddenAttributes', ['*@style', '*@onclick', '*@onload', '*@onerror']);
        $config->set('URI.DisableExternalResources', true);   // внешние картинки — только по кнопке, позже
        $config->set('HTML.TargetBlank', true);
        $config->set('AutoFormat.RemoveEmpty', true);

        return (new \HTMLPurifier($config))->purify($html);
    }

    private function folderTitle(Folder $folder): string
    {
        $name = $folder->name;

        return match (strtoupper($name)) {
            'INBOX' => 'Входящие',
            'SENT', 'SENT ITEMS', 'SENT MESSAGES' => 'Отправленные',
            'DRAFTS' => 'Черновики',
            'JUNK', 'SPAM' => 'Спам',
            'TRASH', 'DELETED ITEMS' => 'Корзина',
            'ARCHIVE' => 'Архив',
            default => $name,
        };
    }

    private function folderOrder(string $path): int
    {
        return match (strtoupper($path)) {
            'INBOX' => 0, 'DRAFTS' => 1, 'SENT' => 2, 'JUNK', 'SPAM' => 3, 'TRASH' => 4, 'ARCHIVE' => 5,
            default => 10,
        };
    }

    private function safeUnread(Folder $folder): ?int
    {
        try {
            $status = $folder->status();

            return isset($status['unseen']) ? (int) $status['unseen'] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
