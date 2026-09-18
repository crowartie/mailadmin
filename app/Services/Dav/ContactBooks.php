<?php

namespace App\Services\Dav;

use App\Dav\CardBackend;
use App\Dav\Server;
use Illuminate\Support\Facades\DB;

/** Адресные книги: список, карточки, поиск, перенос, импорт и выгрузка. */
class ContactBooks
{
    public function __construct(
        private readonly DavAccess $a,
    ) {
    }


    // ── Адресные книги ───────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function books(string $user): array
    {
        $this->a->ensureOnce($user);
        $out = [];
        foreach ($this->a->cards->getAddressBooksForUser(Server::principal($user)) as $b) {
            $count = (int) DB::table('dav_cards')->where('addressbookid', $b['id'])->count();
            $kind = ! empty($b['unit']) ? 'unit' : (! empty($b['shared']) ? $b['uri'] : ($b['uri'] === DavAccess::PERSONAL ? 'personal' : 'own'));
            $out[] = [
                'id' => (int) $b['id'],
                'uri' => $b['uri'],
                'name' => $b['{DAV:}displayname'] ?: $b['uri'],
                'description' => $b['{urn:ietf:params:xml:ns:carddav}addressbook-description'] ?? '',
                'kind' => $kind,
                'readonly' => ! empty($b['shared']) && empty($b['unit']) && ! $this->a->isAdmin($user),
                'count' => $count,
            ];
        }
        $rank = ['personal' => 0, 'own' => 1, 'unit' => 2, 'employees' => 3, 'company' => 4];
        usort($out, fn ($a, $b) => ($rank[$a['kind']] ?? 9) <=> ($rank[$b['kind']] ?? 9));

        return $out;
    }


    private function book(string $user, string $uri): array
    {
        foreach ($this->books($user) as $b) {
            if ($b['uri'] === $uri) {
                return $b;
            }
        }
        throw new DavException('Адресная книга не найдена', 404);
    }


    /** @return array<int,array<string,mixed>> */
    public function cards(string $user, ?string $bookUri = null, string $q = ''): array
    {
        $books = $bookUri ? [$this->book($user, $bookUri)] : $this->books($user);
        $q = mb_strtolower(trim($q));
        $out = [];
        foreach ($books as $b) {
            $rows = DB::table('dav_cards')->where('addressbookid', $b['id'])->get(['uri', 'carddata', 'etag']);
            foreach ($rows as $row) {
                $c = Cards::parse((string) $row->carddata, $row->uri, $row->etag);
                $c['book'] = $b['uri'];
                $c['bookName'] = $b['name'];
                $c['readonly'] = $b['readonly'];
                if ($q !== '' && ! $this->matches($c, $q)) {
                    continue;
                }
                unset($c['photo']);
                $out[] = $c;
            }
        }
        usort($out, fn ($a, $b) => strcoll(mb_strtolower($a['fn']), mb_strtolower($b['fn'])));

        return $out;
    }


    private function matches(array $c, string $q): bool
    {
        $hay = mb_strtolower(implode(' ', array_merge(
            [$c['fn'], $c['org'], $c['title'], $c['note'], $c['nick'], $c['department']],
            array_column($c['emails'], 'value'),
            array_column($c['phones'], 'value'),
            $c['groups']
        )));
        foreach (preg_split('/\s+/', $q) as $word) {
            if ($word !== '' && ! str_contains($hay, $word)) {
                return false;
            }
        }

        return true;
    }


    public function card(string $user, string $bookUri, string $uri): array
    {
        $b = $this->book($user, $bookUri);
        $row = DB::table('dav_cards')->where('addressbookid', $b['id'])->where('uri', $uri)->first(['uri', 'carddata', 'etag']);
        if (! $row) {
            throw new DavException('Контакт не найден', 404);
        }
        $c = Cards::parse((string) $row->carddata, $row->uri, $row->etag);
        $c['book'] = $b['uri'];
        $c['bookName'] = $b['name'];
        $c['readonly'] = $b['readonly'];
        $c['raw'] = (string) $row->carddata;

        return $c;
    }


    /** Создать или обновить карточку. @return array карточка после сохранения */
    public function saveCard(string $user, string $bookUri, ?string $uri, array $data): array
    {
        $b = $this->book($user, $bookUri);
        $existing = null;
        if ($uri) {
            $existing = DB::table('dav_cards')->where('addressbookid', $b['id'])->where('uri', $uri)->value('carddata');
        }
        $vcf = Cards::build($data, $existing ? (string) $existing : null);
        $uri = $uri ?: Cards::uid() . '.vcf';
        $this->a->dav($user, 'PUT', "addressbooks/{$user}/{$bookUri}/{$uri}", $vcf, ['Content-Type' => 'text/vcard; charset=utf-8']);

        return $this->card($user, $bookUri, $uri);
    }


    public function deleteCard(string $user, string $bookUri, string $uri): void
    {
        $this->a->dav($user, 'DELETE', "addressbooks/{$user}/{$bookUri}/{$uri}");
    }


    /** Перенести карточку в другую книгу (копия + удаление). */
    public function moveCard(string $user, string $fromBook, string $uri, string $toBook): array
    {
        $c = $this->card($user, $fromBook, $uri);
        $this->a->dav($user, 'PUT', "addressbooks/{$user}/{$toBook}/{$uri}", $c['raw'], ['Content-Type' => 'text/vcard; charset=utf-8']);
        $this->deleteCard($user, $fromBook, $uri);

        return $this->card($user, $toBook, $uri);
    }


    /** Импорт .vcf (одна или много карточек). @return int сколько добавлено */
    /**
     * Загрузить карточки из .vcf. Повторный импорт того же файла удваивал всю книгу —
     * теперь уже имеющиеся карточки пропускаем: сверяем по адресу почты, а если его нет —
     * по имени вместе с телефоном.
     *
     * @return array{imported:int,skipped:int}
     */
    public function importCards(string $user, string $bookUri, string $vcfText): array
    {
        $known = [];
        foreach ($this->cards($user, $bookUri, '') as $c) {
            foreach (self::cardKeys($c) as $k) {
                $known[$k] = true;
            }
        }

        $n = 0;
        $skipped = 0;
        foreach (Cards::split($vcfText) as $vcf) {
            // Чужая карточка может быть vCard 4.0 или без FN — прогоняем через свою сборку.
            $parsed = Cards::parse($vcf);
            $keys = self::cardKeys($parsed);
            if ($keys && array_intersect_key($known, array_flip($keys))) {
                $skipped++;
                continue;
            }
            $uri = Cards::uid() . '.vcf';
            $normalized = Cards::build($parsed, $vcf);
            $this->a->dav($user, 'PUT', "addressbooks/{$user}/{$bookUri}/{$uri}", $normalized, ['Content-Type' => 'text/vcard; charset=utf-8']);
            foreach ($keys as $k) {
                $known[$k] = true;
            }
            $n++;
        }

        return ['imported' => $n, 'skipped' => $skipped];
    }


    /**
     * Чем отличаем карточку от уже имеющейся: адресами почты, а если их нет — именем с телефоном.
     *
     * @return string[]
     */
    private static function cardKeys(array $c): array
    {
        $keys = [];
        foreach ((array) ($c['emails'] ?? []) as $e) {
            $m = mb_strtolower(trim((string) ($e['value'] ?? $e)));
            if ($m !== '') {
                $keys[] = 'm:' . $m;
            }
        }
        if (! $keys) {
            $name = mb_strtolower(trim((string) ($c['fn'] ?? '')));
            foreach ((array) ($c['phones'] ?? []) as $p) {
                $digits = preg_replace('/\D+/', '', (string) ($p['value'] ?? $p)) ?? '';
                if ($name !== '' && strlen($digits) >= 7) {
                    $keys[] = 'np:' . $name . '|' . substr($digits, -10);
                }
            }
        }

        return $keys;
    }


    public function exportCards(string $user, ?string $bookUri): string
    {
        $books = $bookUri ? [$this->book($user, $bookUri)] : $this->books($user);
        $out = '';
        foreach ($books as $b) {
            foreach (DB::table('dav_cards')->where('addressbookid', $b['id'])->pluck('carddata') as $data) {
                $out .= rtrim((string) $data) . "\r\n";
            }
        }

        return $out;
    }


    /** Все адреса из книг пользователя для автодополнения. @return array<int,array{mail:string,name:string,kind:string}> */
    public function suggest(string $user, string $q, int $limit = 8): array
    {
        $q = mb_strtolower(trim($q));
        $out = [];
        foreach ($this->cards($user, null, $q) as $c) {
            foreach ($c['emails'] as $e) {
                if (! isset($out[$e['value']])) {
                    $out[$e['value']] = ['mail' => $e['value'], 'name' => $c['fn'], 'kind' => $c['book'] === 'employees' ? 'employee' : 'contact'];
                }
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_values($out);
    }


    // ── Общие книги (для админки и синхронизации сотрудников) ──────────

    public function systemCards(): CardBackend
    {
        $this->a->cards->systemWrites = true;

        return $this->a->cards;
    }


    public function systemBookId(string $uri): int
    {
        $id = array_search($uri, $this->a->cards->systemBooks(), true);
        if ($id === false) {
            throw new DavException("Общей книги «{$uri}» нет", 404);
        }

        return (int) $id;
    }
}
