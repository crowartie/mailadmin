<?php

namespace App\Services\Mail;

use Carbon\Carbon;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

/**
 * Строка списка писем: тема, отправитель, дата, флажки, первые слова.
 *
 * Собирается двумя путями — из ответа сервера на быстрый FETCH заголовков и из
 * объекта письма библиотеки. Оба должны давать одно и то же, поэтому лежат рядом.
 */
final class MessageSummary
{
    public function __construct(private readonly Client $client)
    {
    }


    /** Строка списка из сырого ответа FETCH — те же поля, что даёт summary(). */
    public function summaryFromFetch(array $row): array
    {
        // Библиотека режет «BODY[HEADER.FIELDS (FROM …)]» на ключ «BODY[HEADER.FIELDS» и список, где последний элемент — сам текст заголовков.
        $headers = '';
        foreach ($row as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'BODY[')) {
                $headers = is_array($value) ? (string) (end($value) ?: '') : (string) $value;
            }
        }
        $h = Mime::parseHeaderFields($headers);
        $flags = array_map('strtolower', array_map('strval', (array) ($row['FLAGS'] ?? [])));
        $labels = [];
        foreach ($flags as $flag) {
            if (preg_match('/^lbl_(\d+)$/', $flag, $m)) {
                $labels[] = (int) $m[1];
            }
        }
        $subject = trim((string) Charset::header($h['subject'] ?? ''));
        // Тот же подхват имени из общей книги, что и в summary(): этот путь собирает
        // строку списка напрямую из заголовков, минуя объект письма.
        $from = ($f = Mime::senderOf($h)) ? Directory::fill($f) : null;
        $to = ($t = Mime::firstAddress($h['to'] ?? '')) ? Directory::fill($t) : null;
        $date = null;
        // Кривой или невозможный Date: — берём время получения (INTERNALDATE).
        foreach ([$h['date'] ?? null, $row['INTERNALDATE'] ?? null] as $raw) {
            if (($d = Mime::parseDate($raw === null ? null : (string) $raw)) !== null) {
                $date = $d->toIso8601String();
                break;
            }
        }
        $preview = null;
        if (isset($row['PREVIEW']) && is_string($row['PREVIEW'])) {
            $text = self::withoutLinksBlock(trim(preg_replace('/\s+/u', ' ', (string) Charset::fix($row['PREVIEW'])) ?? ''));
            $preview = $text !== '' ? mb_substr($text, 0, 160) : null;
        }

        return [
            'uid' => (int) $row['UID'],
            'subject' => $subject !== '' ? $subject : '(без темы)',
            'from' => $from ?? Mime::NO_SENDER,
            'toName' => $to['name'] ?? null,
            'date' => $date,
            'seen' => in_array('\\seen', $flags, true),
            'flagged' => in_array('\\flagged', $flags, true),
            'answered' => in_array('\\answered', $flags, true),
            'hasAttachments' => str_contains(strtolower($h['content-type'] ?? ''), 'multipart/mixed'),
            'size' => (int) ($row['RFC822.SIZE'] ?? 0),
            'labels' => $labels,
            'messageId' => trim((string) ($h['message-id'] ?? ''), " \t<>"),
            'preview' => $preview,
        ];
    }

    public function previews(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $conn = $this->client->getConnection();
        $set = implode(',', array_map('intval', $uids));
        $out = [];
        // Длинные PREVIEW (например, отчёты Logwatch) библиотека разбирает с предупреждениями «Uninitialized string offset».
        // Laravel превращает предупреждение в исключение, разбор обрывается на середине ответа, и следующая команда
        // читает недочитанный хвост («Empty response», 500 на всю страницу). Поэтому на время разбора предупреждения глушим.
        set_error_handler(fn () => true, E_WARNING | E_NOTICE | E_DEPRECATED);
        try {
            foreach ((array) $conn->fetch(['PREVIEW'], $uids)->data() as $uid => $text) {
                if (is_string($text) && ! str_starts_with($text, '"')) {
                    $out[(int) $uid] = $text;
                }
            }
            foreach ((array) $conn->requestAndResponse('UID FETCH', [$set, '(PREVIEW)'], true)->data() as $line) {
                if (preg_match('/^\d+ FETCH \(UID (\d+) PREVIEW "((?:[^"\\\\]|\\\\.)*)"\)/s', (string) $line, $m)) {
                    $out[(int) $m[1]] = stripcslashes($m[2]);
                }
            }
        } catch (\Throwable) {
            return [];
        } finally {
            restore_error_handler();
        }
        foreach ($out as $uid => $text) {
            $text = self::withoutLinksBlock(trim(preg_replace('/\s+/u', ' ', (string) Charset::fix($text)) ?? ''));
            if ($text === '') {
                unset($out[$uid]);
            } else {
                $out[$uid] = mb_substr($text, 0, 160);
            }
        }

        return $out;
    }

    /**
     * Превью без блока «К этому письму приложены ссылки…» (MailBuilder::linksBlock): в списке
     * вместо текста письма стояли заголовок блока и длинные адреса. Текст до блока остаётся;
     * если его нет — «Файлы: имя, имя». Превью от Dovecot — сплошная строка, адрес в блоке
     * показан раскодированным (с пробелами), поэтому имена идём по порядку: имя (размер) Ссылка…
     * адрес/токен/то же имя.
     */
    public static function withoutLinksBlock(string $text): string
    {
        $title = 'К этому письму приложены ссылки на следующие файлы:';
        $at = strpos($text, $title);
        if ($at === false) {
            return $text;
        }
        $before = trim(substr($text, 0, $at));
        if ($before !== '') {
            return $before;
        }
        $s = ltrim(substr($text, $at + strlen($title)));
        $names = [];
        while (count($names) < 20 && preg_match('#^(.+?) \(\d[\d.,]* ?(?:Б|КБ|МБ|ГБ)\) ?Ссылка для скачивания: ?https?://\S+?/[A-Za-z0-9_-]{20,64}/#u', $s, $m)) {
            $names[] = $m[1];
            $s = ltrim(substr($s, strlen($m[0])));
            if (str_starts_with($s, $m[1])) {
                $s = ltrim(substr($s, strlen($m[1])));
            }
            $s = (string) preg_replace('/^Ссылка защищена паролем[^.]*\.\s*/u', '', $s);
        }

        return $names ? 'Файлы: ' . implode(', ', $names) : 'Файлы по ссылкам';
    }

    /** Размер письма; если сервер не ответил — 0, а не 500 на весь список. */
    private function sizeOf(Message $message): int
    {
        try {
            return (int) $message->getSize();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Отправитель письма, с запасным разбором заголовка.
     *
     * Библиотека не понимает старую запись по RFC 822 — «root@host (Cron Daemon)»,
     * адрес без угловых скобок и имя в круглых, — и отдаёт пустоту. Так шлют письма
     * служебные программы самого сервера, и человек видел их без отправителя вовсе.
     * В списке писем тот же заголовок разбирается нашим разборщиком и всё видно,
     * поэтому здесь просто добавляем тот же запасной путь.
     *
     * Письмо без From вовсе (черновик Outlook) подписывается по Sender/Reply-To,
     * а если и их нет — «Без отправителя», как и в списке (Mime::senderOf).
     *
     * @return array{name:string,mail:string}
     */
    private static function fromOf(Message $message): array
    {
        $first = $message->getFrom()->first();
        $out = $first ? Directory::fill(Mime::address($first->personal, $first->mail)) : null;
        if (($out['mail'] ?? '') !== '') {
            return $out;
        }
        $fields = Mime::parseHeaderFields((string) ($message->getHeader()?->raw ?? ''));
        if (($parsed = Mime::senderOf($fields)) !== null) {
            return Directory::fill($parsed);
        }

        return $out ?? Mime::NO_SENDER;
    }

    /**
     * Время получения письма — запасная дата, когда заголовка Date нет или он кривой.
     *
     * Сервер знает его всегда, и список писем этим уже пользуется. При открытии письма
     * запасного пути не было, и письмо показывалось без даты вовсе. Спрашиваем только
     * для таких писем: обычным это ничего не стоит.
     */
    private static function receivedAt(Client $client, int $uid): ?string
    {
        if ($uid < 1) {
            return null;
        }
        try {
            $rows = (array) $client->getConnection()->fetch(['UID', 'INTERNALDATE'], [$uid], null, \Webklex\PHPIMAP\IMAP::ST_UID)->data();
            // По ключу брать нельзя: библиотека режет строку в кавычках по пробелам,
            // и «"31-Aug-2026 08:54:48 +0800"» превращается в значение «"31-Aug-2026»
            // плюс мусорный ключ «08:54:48». Ищем дату в ответе по её собственному виду.
            $flat = '';
            array_walk_recursive($rows, function ($v, $k) use (&$flat) {
                $flat .= ' ' . (string) $k . ' ' . (string) $v;
            });
            if (preg_match('/(\\d{1,2}-[A-Za-z]{3}-\\d{4}\\s+\\d{1,2}:\\d{2}:\\d{2}\\s*[+-]\\d{4})/', $flat, $m)) {
                return Carbon::parse($m[1])->toIso8601String();
            }
        } catch (\Throwable) {
            // Без даты письмо всё равно откроется — это не повод ронять показ.
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function summary(Message $message, ?string $preview = null): array
    {
        $from = $message->getFrom()->first();
        $to = $message->getTo()->first();
        $date = $message->getDate()->first();
        $subject = trim((string) Charset::header((string) ($message->getSubject()->first() ?? '')));
        $flags = $message->getFlags();
        $contentType = strtolower((string) ($message->getHeader()?->get('content_type')?->first() ?? ''));

        $labels = [];
        foreach ($flags->toArray() as $flag) {
            if (preg_match('/^Lbl_(\d+)$/i', (string) $flag, $m)) {
                $labels[] = (int) $m[1];
            }
        }

        return [
            'uid' => $message->getUid(),
            'subject' => $subject !== '' ? $subject : '(без темы)',
            // Если отправитель не подписался именем, берём его из общей книги сотрудников:
            // иначе в списке стоит «popovav@innotec.su» вместо «Попов Андрей Викторович».
            'from' => self::fromOf($message),
            'toName' => $to ? Directory::fill(Mime::address($to->personal, $to->mail))['name'] : null,
            // Дата библиотеки бывает невозможной («0200» из пояса без знака) — тогда время получения.
            'date' => $date && Mime::plausible($date) ? $date->toIso8601String()
                : (($d = Mime::parseDate((string) $date)) ? $d->toIso8601String() : self::receivedAt($this->client, (int) $message->getUid())),
            'seen' => $flags->has('seen'),
            'flagged' => $flags->has('flagged'),
            'answered' => $flags->has('answered'),
            'hasAttachments' => str_contains($contentType, 'multipart/mixed'),
            'size' => $this->sizeOf($message),
            'labels' => $labels,
            'messageId' => trim((string) ($message->getMessageId()->first() ?? ''), '<>'),
            'preview' => $preview,
        ];
    }
}
