<?php

namespace App\Services;

use App\Models\Vmail\Mailbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Общая адресная книга компании в SOGo (CardDAV).
 *
 * Карточка сотрудника создаётся вместе с ящиком и удаляется вместе с ним —
 * это требование заказчика: «помимо почты должно подтянуться и в адресную книгу».
 * Сбой здесь не должен ломать создание ящика: пишем в журнал и идём дальше.
 */
class AddressBookService
{
    public function enabled(): bool
    {
        return filled(config('services.sogo.carddav_url'));
    }

    public function put(Mailbox $mailbox): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $response = Http::withBasicAuth(config('services.sogo.user'), config('services.sogo.password'))
                ->withHeaders(['Content-Type' => 'text/vcard; charset=utf-8'])
                ->timeout(5)
                ->send('PUT', $this->url($mailbox), ['body' => $this->vcard($mailbox)]);

            if ($response->failed()) {
                Log::warning('Адресная книга: SOGo ответил ошибкой', [
                    'user' => $mailbox->username, 'status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Адресная книга недоступна', ['user' => $mailbox->username, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function delete(Mailbox $mailbox): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            Http::withBasicAuth(config('services.sogo.user'), config('services.sogo.password'))
                ->timeout(5)
                ->delete($this->url($mailbox));
        } catch (\Throwable $e) {
            Log::warning('Адресная книга: не удалось удалить карточку', ['user' => $mailbox->username, 'error' => $e->getMessage()]);
        }
    }

    private function url(Mailbox $mailbox): string
    {
        // Имя файла — сам адрес: повторное создание перезапишет карточку, а не продублирует.
        return rtrim(config('services.sogo.carddav_url'), '/') . '/' . rawurlencode($mailbox->username) . '.vcf';
    }

    private function vcard(Mailbox $mailbox): string
    {
        $esc = fn (?string $v) => str_replace([';', ',', "\n"], ['\\;', '\\,', '\\n'], (string) $v);

        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'UID:' . $mailbox->username,
            'FN:' . $esc($mailbox->name ?: $mailbox->username),
            'N:' . $esc($mailbox->last_name) . ';' . $esc($mailbox->first_name) . ';;;',
            'EMAIL;TYPE=INTERNET,WORK,PREF:' . $mailbox->username,
        ];

        if ($mailbox->telephone) {
            $lines[] = 'TEL;TYPE=WORK,VOICE:' . $esc($mailbox->telephone);
        }
        if ($mailbox->mobile) {
            $lines[] = 'TEL;TYPE=CELL:' . $esc($mailbox->mobile);
        }
        if ($mailbox->department || $mailbox->rank) {
            $lines[] = 'ORG:' . $esc(config('app.company', 'innotec.su')) . ';' . $esc($mailbox->department);
            $lines[] = 'TITLE:' . $esc($mailbox->rank);
        }

        $lines[] = 'REV:' . now()->utc()->format('Ymd\THis\Z');
        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines) . "\r\n";
    }
}
