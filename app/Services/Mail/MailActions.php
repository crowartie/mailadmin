<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Carbon\Carbon;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;

/**
 * Действия над письмами: флаги, перенос, копия, удаление, добавление письма в папку,
 * а также поиск по IMAP для правил и решений по отправителям.
 *
 * Права на чужую папку спрашиваются у сервера (MYRIGHTS) и запоминаются на время
 * запроса: без этого «только чтение» выяснялось бы уже по отказу на каждое действие.
 */
final class MailActions
{
    public function __construct(
        private readonly Client $client,
        private readonly FolderTree $tree,
        // Индекс цепочек умеет работать только со складом целиком: передаём его сюда,
        // чтобы после переноса и удаления сразу поправить затронутые папки.
        private readonly MailStore $store,
    ) {
    }

    // ── Действия ─────────────────────────────────────────────────────────

    /** @var array<string,string> права на папки за время запроса (MYRIGHTS) */
    private array $rightsCache = [];

    /**
     * Права на папку по ACL. Буквы RFC 4314: s — менять «прочитано», t — «удалено»,
     * w — остальные пометки и метки, e — очищать папку.
     * Сервер без ACL или своя папка — считаем, что можно всё.
     */
    private function rights(string $path): string
    {
        if (isset($this->rightsCache[$path])) {
            return $this->rightsCache[$path];
        }
        $all = 'acdeilprstwx';
        try {
            $conn = $this->client->getConnection();
            $r = $conn->requestAndResponse('MYRIGHTS', [$conn->escapeString($path)]);
            $line = implode(' ', array_map(fn ($x) => is_array($x) ? implode(' ', array_map('strval', $x)) : (string) $x, (array) $r->getResponse()));
            $rights = preg_match('/MYRIGHTS\s+\S+\s+([a-zA-Z]+)/i', $line, $m) ? $m[1] : $all;
        } catch (\Throwable) {
            $rights = $all;
        }

        return $this->rightsCache[$path] = $rights;
    }

    /** Установить или снять флаг у набора писем (\Seen, \Flagged, \Answered, Lbl_N …). */
    public function flag(string $path, array $uids, string $flag, bool $on): void
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        if (! $uids) {
            return;
        }
        // Дело не только в отказах: в общей папке «только для просмотра» Dovecot отвечает на
        // команду OK, но пометку не сохраняет (её нет в PERMANENTFLAGS). Веб-почта показывала
        // успех, а через секунду письмо снова было непрочитанным. Спрашиваем права заранее.
        $need = match (strtolower($flag)) {
            '\\seen' => 's',
            '\\deleted' => 't',
            default => 'w',
        };
        $rights = $this->rights($path);
        if ($rights !== '' && ! str_contains(strtolower($rights), $need)) {
            throw MailException::denied(match ($need) {
                's' => 'Отметить прочитанным нельзя: владелец открыл эту папку только для просмотра.',
                't' => 'Удалить письмо отсюда нельзя: владелец открыл эту папку только для просмотра.',
                default => 'Поставить пометку или метку здесь нельзя: владелец открыл эту папку только для просмотра.',
            });
        }
        $this->client->openFolder($path, true);
        $conn = $this->client->getConnection();
        foreach (array_chunk($uids, 200) as $chunk) {
            // Библиотечный store() умеет только диапазоны, а нам нужен произвольный набор UID.
            $r = $conn->requestAndResponse('UID STORE', [implode(',', $chunk), ($on ? '+' : '-') . 'FLAGS.SILENT', $conn->escapeList([$flag])]);
            // Ответ сервера не проверялся: в общей папке «только для просмотра» отметка
            // «прочитано» возвращала успех, а через секунду письмо снова было непрочитанным.
            Mime::assertOk($r, 'Не удалось изменить пометку письма');
        }
    }

    public function move(string $path, array $uids, string $target): void
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        if (! $uids || $target === $path) {
            return;
        }
        $this->client->openFolder($path, true);
        $this->client->getConnection()->moveManyMessages($uids, $target, IMAP::ST_UID);
        $this->tree->forgetCache();
        ThreadIndex::touch($this->store, [$path, $target]);
    }

    /** Удалить: из корзины — навсегда, откуда угодно ещё — в корзину. */
    public function delete(string $path, array $uids): void
    {
        $trash = $this->tree->rolePathFor($path, 'trash');
        if ($path === $trash) {
            $this->flag($path, $uids, '\\Deleted', true);
            $this->client->openFolder($path, true);
            // UID EXPUNGE убирает ровно выбранные письма. Обычный EXPUNGE сносит из папки всё
            // помеченное к удалению — в том числе то, что человек пометил в Outlook или на телефоне
            // и там ещё видит: такие письма исчезали навсегда.
            $this->expungeUids($this->client->getConnection(), array_values(array_unique(array_map('intval', $uids))));
            // Иначе панель папок ещё минуту показывает старое «Корзина (12)»: кэш списка папок
            // сбрасывают move() и emptyFolder(), а эта ветка — нет.
            $this->tree->forgetCache();

            return;
        }
        $this->move($path, $uids, $trash);
    }

    public function emptyFolder(string $path): void
    {
        $rights = strtolower($this->rights($path));
        if ($rights !== '' && (! str_contains($rights, 't') || ! str_contains($rights, 'e'))) {
            throw MailException::denied('Очистить эту папку нельзя: владелец открыл её только для просмотра.');
        }
        $this->client->openFolder($path, true);
        $conn = $this->client->getConnection();
        $r = $conn->requestAndResponse('STORE', ['1:*', '+FLAGS.SILENT', $conn->escapeList(['\\Deleted'])]);
        // Результат не проверялся, и при отказе сервера человек получал сообщение об успехе.
        Mime::assertOk($r, 'Не удалось очистить папку');
        $conn->expunge();
        $this->tree->forgetCache();
    }

    /**
     * Убрать из папки именно эти письма. Если сервер не умеет UIDPLUS, откатываемся
     * на обычный EXPUNGE — иначе письма останутся лежать помеченными к удалению.
     */
    private function expungeUids(mixed $conn, array $uids): void
    {
        if ($uids === []) {
            return;
        }
        // Пробуем UID EXPUNGE (RFC 4315). Сервер без него ответит BAD — тогда обычный EXPUNGE:
        // проверять возможности отдельной командой ради одного удаления незачем.
        try {
            foreach (array_chunk($uids, 200) as $chunk) {
                $conn->requestAndResponse('UID EXPUNGE', [implode(',', $chunk)]);
            }
        } catch (\Throwable) {
            $conn->expunge();
        }
    }

    /** UID письма по Message-ID в папке (для отложенных и напоминаний). */
    public function findByMessageId(string $path, string $messageId): ?int
    {
        try {
            $m = $this->tree->folder($path)->query()->setFetchBody(false)->whereMessageId($messageId)->limit(1)->get()->first();

            return $m?->getUid();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Сколько последних писем папки просматриваем в поисках ответа.
     *
     * Ответ приходит после исходного письма, а напоминание ставят на дни, не на годы.
     * Триста писем — это с запасом и ровно одно обращение к серверу.
     */
    private const REPLY_SCAN = 300;

    /**
     * По сколько писем спрашиваем заголовки за раз.
     *
     * Целым диапазоном нельзя: на двухстах письмах разбор ответа возвращает пусто,
     * на сорока — исправно. Сорок — размер страницы списка, то есть заведомо рабочий.
     */
    private const REPLY_CHUNK = 40;

    /** Есть ли в папке ответ на письмо с таким Message-ID. */
    public function hasReplyTo(string $path, string $messageId): bool
    {
        $id = trim($messageId, " \t<>");
        if ($id === '') {
            return false;
        }
        try {
            // Не поиском: полнотекстовый указатель сервера (fts_xapian) знает только
            // From, To, Cc, Bcc, Subject и Message-ID, а поиск по In-Reply-To
            // и References уходит к нему же и молча отвечает «ничего». Из-за этого
            // напоминание «на письмо не ответили» приходило даже тогда, когда ответ
            // лежал в той же папке. Поэтому читаем заголовки сами — одной командой.
            $total = (int) ($this->tree->folder($path)->examine()['exists'] ?? 0);
            if ($total < 1) {
                return false;
            }
            $this->client->openFolder($path, true);
            $conn = $this->client->getConnection();
            $stop = max(1, $total - self::REPLY_SCAN + 1);
            // Идём от новых к старым: ответ обычно среди последних писем.
            for ($hi = $total; $hi >= $stop; $hi -= self::REPLY_CHUNK) {
                $lo = max($stop, $hi - self::REPLY_CHUNK + 1);
                // UID в запросе обязателен: по нему библиотека раскладывает ответ
                // по письмам, а без него молча отдаёт пустой список.
                $rows = (array) $conn->fetch(['UID', 'BODY.PEEK[HEADER.FIELDS (IN-REPLY-TO REFERENCES)]'], $lo, $hi, IMAP::ST_MSGN)->data();
                foreach ($rows as $row) {
                    foreach ((array) $row as $key => $value) {
                        if (! is_string($key) || ! str_starts_with($key, 'BODY[')) {
                            continue;
                        }
                        $text = is_array($value) ? (string) (end($value) ?: '') : (string) $value;
                        if ($text !== '' && str_contains($text, $id)) {
                            return true;
                        }
                    }
                }
            }

            return false;
        } catch (\Throwable $e) {
            // Промолчать тут нельзя: «ответа нет» — это повод разбудить человека письмом.
            \Illuminate\Support\Facades\Log::warning('проверка ответа не удалась (' . $path . '): ' . $e->getMessage());

            return false;
        }
    }

    /** Положить готовое письмо в папку; вернуть его UID (ищем по Message-ID). */
    public function append(string $path, string $raw, array $flags = ['\\Seen'], ?string $messageId = null): ?int
    {
        $this->tree->folder($path)->appendMessage($raw, $flags, Carbon::now());
        ThreadIndex::touch($this->store, [$path]);
        if ($messageId) {
            return $this->findByMessageId($path, $messageId);
        }

        return null;
    }

    // ── Поиск по IMAP для правил и решений по отправителям ─────────────

    public function copy(string $path, array $uids, string $target): void
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        if (! $uids || $target === $path) {
            return;
        }
        $this->client->openFolder($path, true);
        $this->client->getConnection()->copyManyMessages($uids, $target, IMAP::ST_UID);
        ThreadIndex::touch($this->store, [$target]);
    }

    /** @return int[] все UID папки */
    public function searchAll(string $path): array
    {
        $this->client->openFolder($path, true);
        $r = $this->client->getConnection()->search(['ALL'], IMAP::ST_UID)->validatedData();

        return array_values(array_map('intval', is_array($r) ? $r : []));
    }

    /** UID писем отправителя: точный адрес или домен (без поддоменов). @return int[] */
    public function searchSender(string $path, string $match, string $value): array
    {
        $value = strtolower($value);
        $needle = $match === 'domain' ? '@' . $value : $value;
        $out = [];
        foreach ($this->headerIndex($path) as $uid => $h) {
            foreach ($h['from'] as $a) {
                if ($match === 'domain' ? str_ends_with($a, $needle) : $a === $needle) {
                    $out[] = $uid;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Условие правила {field, op, value, header?} → UID. Заголовки читаются целиком и сравниваются здесь:
     * IMAP SEARCH через полнотекстовый индекс режет длинные адреса и пропускает письма.
     *
     * @return int[]
     */
    public function searchCondition(string $path, array $c): array
    {
        $field = $c['field'] ?? 'subject';
        $op = $c['op'] ?? 'contains';
        $value = mb_strtolower(trim((string) ($c['value'] ?? '')));
        if ($value === '') {
            return [];
        }
        $header = strtolower((string) ($c['header'] ?? 'subject'));
        $out = [];
        foreach ($this->headerIndex($path) as $uid => $h) {
            $cands = match ($field) {
                'from' => $h['from'],
                'to' => $h['to'],
                'recipient' => array_merge($h['to'], $h['cc']),
                'header' => $h['raw'][$header] ?? [],
                default => [$h['subject']],
            };
            $hit = false;
            foreach ($cands as $cand) {
                $hit = match ($op) {
                    'is' => $cand === $value,
                    'starts' => str_starts_with($cand, $value),
                    'ends' => str_ends_with($cand, $value),
                    default => str_contains($cand, $value),
                };
                if ($hit) {
                    break;
                }
            }
            if ($op === 'not_contains' ? ! $hit : $hit) {
                $out[] = $uid;
            }
        }

        return $out;
    }

    /** @var array<string,array<int,array{from:string[],to:string[],cc:string[],subject:string,raw:array<string,string[]>}>> */
    private array $headerIndex = [];

    /**
     * Заголовки всех писем папки (адреса в нижнем регистре, тема раскодирована). Читается один раз за запрос,
     * порциями по 500 писем — для папки в десятки тысяч писем это секунды.
     */
    private function headerIndex(string $path): array
    {
        if (isset($this->headerIndex[$path])) {
            return $this->headerIndex[$path];
        }
        $uids = $this->searchAll($path);
        $conn = $this->client->getConnection();
        $index = [];
        foreach (array_chunk($uids, 500) as $chunk) {
            $raw = $conn->headers($chunk, 'RFC822', IMAP::ST_UID)->validatedData();
            foreach ($chunk as $uid) {
                $text = (string) ($raw[$uid] ?? '');
                if ($text === '') {
                    continue;
                }
                $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $text);
                $fields = [];
                if (preg_match_all('/^([A-Za-z0-9-]+):[ \t]*(.*)$/m', $unfolded, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $m) {
                        $fields[strtolower($m[1])][] = mb_strtolower((string) Charset::header(trim($m[2])));
                    }
                }
                $addr = function (string $name) use ($fields): array {
                    $out = [];
                    foreach ($fields[$name] ?? [] as $line) {
                        preg_match_all('/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+/i', $line, $aa);
                        foreach ($aa[0] as $a) {
                            $out[] = strtolower($a);
                        }
                    }

                    return $out;
                };
                $index[(int) $uid] = [
                    'from' => $addr('from'), 'to' => $addr('to'), 'cc' => $addr('cc'),
                    'subject' => $fields['subject'][0] ?? '', 'raw' => $fields,
                ];
            }
        }

        return $this->headerIndex[$path] = $index;
    }
}
