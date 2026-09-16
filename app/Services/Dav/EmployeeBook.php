<?php

namespace App\Services\Dav;

use App\Models\Vmail\Domain;
use App\Models\Vmail\Mailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Общая книга «Сотрудники»: одна карточка на каждый ящик сервера.
 * Заполняется при создании/правке ящика в админке и раз в час полностью сверяется
 * с таблицей mailbox (на случай правок через iRedAdmin или SQL).
 */
class EmployeeBook
{
    public const BOOK = 'employees';

    public function __construct(private readonly DavStore $store)
    {
    }

    public static function uri(string $username): string
    {
        return 'employee-' . md5(strtolower($username)) . '.vcf';
    }

    /** Карточка сотрудника создана/обновлена. Ошибки — в журнал, ящик от них не зависит. */
    public function put(Mailbox $mailbox): void
    {
        if (in_array($mailbox->username, \App\Models\EmployeeProfile::serviceUsernames(), true)) {
            $this->remove($mailbox);

            return;
        }
        try {
            $this->write($mailbox);
            $this->store->ensureUser($mailbox->username);
        } catch (\Throwable $e) {
            Log::warning('Книга сотрудников: карточка не записана', ['user' => $mailbox->username, 'error' => $e->getMessage()]);
        }
    }

    public function remove(Mailbox|string $mailbox): void
    {
        $username = $mailbox instanceof Mailbox ? $mailbox->username : $mailbox;
        try {
            $bookId = $this->store->systemBookId(self::BOOK);
            if (DB::table('dav_cards')->where('addressbookid', $bookId)->where('uri', self::uri($username))->exists()) {
                $this->store->systemCards()->deleteCard($bookId, self::uri($username));
            }
        } catch (\Throwable $e) {
            Log::warning('Книга сотрудников: карточка не удалена', ['user' => $username, 'error' => $e->getMessage()]);
        }
    }

    /** Полная сверка: добавить недостающих, обновить изменившихся, убрать удалённых. @return array{added:int,updated:int,removed:int} */
    public function sync(): array
    {
        $bookId = $this->store->systemBookId(self::BOOK);
        $existing = DB::table('dav_cards')->where('addressbookid', $bookId)->pluck('etag', 'uri')->all();
        $stats = ['added' => 0, 'updated' => 0, 'removed' => 0];
        $seen = [];

        foreach (Mailbox::query()->people()->orderBy('username')->get() as $mailbox) {
            $uri = self::uri($mailbox->username);
            $seen[$uri] = true;
            $vcf = $this->vcard($mailbox);
            $etag = md5($vcf);
            if (! isset($existing[$uri])) {
                $this->store->systemCards()->createCard($bookId, $uri, $vcf);
                $stats['added']++;
            } elseif (trim((string) $existing[$uri], '"') !== $etag) {
                $this->store->systemCards()->updateCard($bookId, $uri, $vcf);
                $stats['updated']++;
            }
        }
        foreach (array_keys($existing) as $uri) {
            if (! isset($seen[$uri])) {
                $this->store->systemCards()->deleteCard($bookId, $uri);
                $stats['removed']++;
            }
        }

        return $stats;
    }

    private function write(Mailbox $mailbox): void
    {
        $bookId = $this->store->systemBookId(self::BOOK);
        $uri = self::uri($mailbox->username);
        $vcf = $this->vcard($mailbox);
        if (! $mailbox->active) {
            $this->remove($mailbox);

            return;
        }
        if (DB::table('dav_cards')->where('addressbookid', $bookId)->where('uri', $uri)->exists()) {
            $this->store->systemCards()->updateCard($bookId, $uri, $vcf);
        } else {
            $this->store->systemCards()->createCard($bookId, $uri, $vcf);
        }
    }

    /** vCard сотрудника: имя из ящика, адрес, организация — описание домена. Без REV, чтобы сверка по etag была стабильной. */
    public function vcard(Mailbox $mailbox): string
    {
        $name = trim((string) $mailbox->name);
        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Русский порядок «Фамилия Имя Отчество»; латинский «First Last» — наоборот.
        $cyr = $name !== '' && preg_match('/\p{Cyrillic}/u', $name);
        $profileMiddle = (string) (\App\Models\EmployeeProfile::query()->where('username', $mailbox->username)->value('middle_name') ?? '');
        [$last, $first, $middle] = match (true) {
            trim((string) $mailbox->last_name) !== '' || trim((string) $mailbox->first_name) !== '' => [trim((string) $mailbox->last_name), trim((string) $mailbox->first_name), $profileMiddle],
            count($parts) >= 3 && $cyr => [$parts[0], $parts[1], implode(' ', array_slice($parts, 2))],
            count($parts) === 2 && $cyr => [$parts[0], $parts[1], ''],
            count($parts) >= 2 => [end($parts), implode(' ', array_slice($parts, 0, -1)), ''],
            count($parts) === 1 => ['', $parts[0], ''],
            default => ['', explode('@', $mailbox->username)[0], ''],
        };
        $org = trim((string) (Domain::query()->where('domain', $mailbox->domain)->value('description') ?? '')) ?: $mailbox->domain;

        $card = new \Sabre\VObject\Component\VCard([
            'VERSION' => '3.0',
            'UID' => 'employee-' . md5(strtolower($mailbox->username)),
            'FN' => $name !== '' ? $name : $mailbox->username,
        ]);
        $card->add('N', [$last, $first, $middle, '', '']);
        $card->add('EMAIL', strtolower($mailbox->username), ['TYPE' => ['WORK', 'INTERNET', 'PREF']]);
        $card->add('ORG', [$org, '']);
        $card->add('CATEGORIES', ['Сотрудники']);
        $card->add('X-EMPLOYEE', '1');

        return $card->serialize();
    }
}
