<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use App\Services\Mail\SenderRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Решение сотрудника об отправителе: не спам / спам / рассылка — по адресу или по домену. */
class SenderController extends Controller
{
    public function mark(Request $request, ImapSession $imap, SenderRules $senders): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:ham,spam,lists'],
            'match' => ['required', 'in:address,domain'],
            'value' => ['required', 'string', 'max:255', 'regex:/^@?[^\s@]+(@[^\s@]+)?$/'],
            'resort' => ['nullable', 'boolean'],
        ]);
        $value = SenderRules::normalize($data['match'], $data['value']);
        if ($data['match'] === 'address' && ! str_contains($value, '@')) {
            abort(422, 'Укажите полный адрес');
        }
        // Свой домен и себя в спам не отправляем — иначе сотрудник отрежет себе рабочую почту.
        $own = strtolower(substr(strrchr($imap->user(), '@'), 1));
        if ($data['kind'] !== 'ham' && ($value === $own || str_ends_with($value, '@' . $own))) {
            abort(422, 'Свой домен нельзя отправить в спам или рассылки — сделайте обычное правило');
        }
        set_time_limit(600);
        try {
            $r = $senders->mark($imap->user(), $data['kind'], $data['match'], $value, $imap);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        $store = new MailStore($imap->client());
        $moved = 0;
        if ($data['resort'] ?? true) {
            $moved = $senders->resort($store, $data['kind'], $data['match'], $value);
        }

        return response()->json($r + ['moved' => $moved, 'folders' => $store->folders()]);
    }
}
