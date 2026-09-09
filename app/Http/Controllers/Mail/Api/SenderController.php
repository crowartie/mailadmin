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
            'kind' => ['required', 'in:ham,spam,lists,folder'],
            'folder' => ['required_if:kind,folder', 'nullable', 'string', 'max:500'],
            'match' => ['required', 'in:address,domain'],
            'value' => ['required', 'string', 'max:255', 'regex:/^@?[^\s@]+(@[^\s@]+)?$/'],
            'resort' => ['nullable', 'boolean'],
        ]);
        $value = SenderRules::normalize($data['match'], $data['value']);
        if ($data['match'] === 'address' && ! str_contains($value, '@')) {
            abort(422, 'Укажите полный адрес');
        }
        // Свой домен в «спам»/«рассылки» не отправляем: эти решения могут стать общими, и сотрудник отрежет
        // рабочую почту всем. Личная папка — дело самого сотрудника, там ограничения нет.
        $own = strtolower(substr(strrchr($imap->user(), '@'), 1));
        $ownDomain = $value === $own || str_ends_with($value, '@' . $own);
        if ($data['kind'] === 'spam' && $ownDomain) {
            abort(422, 'Свой домен нельзя отправить в спам');
        }
        // «Рассылка» своего домена (новости от info@, hr@) — только личное правило, общим для всех оно не станет.
        $personalOnly = $data['kind'] === 'lists' && $ownDomain;
        set_time_limit(600);
        $store = new MailStore($imap->client());
        $folder = null;
        $folderName = null;
        if ($data['kind'] === 'folder') {
            $f = collect($store->folders())->first(fn ($x) => $x['path'] === $data['folder']);
            abort_if(! $f, 422, 'Такой папки нет');
            abort_if(in_array($f['role'], ['inbox', 'sent', 'drafts', 'trash', 'snoozed', 'shared', 'spam'], true), 422, 'В эту папку правило не делается — выберите свою папку');
            $folder = $f['path'];
            $folderName = $f['name'];
        }
        try {
            $r = $senders->mark($imap->user(), $data['kind'], $data['match'], $value, $imap, $folder, $folderName, $personalOnly);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        $moved = 0;
        if ($data['resort'] ?? true) {
            $moved = $senders->resort($store, $data['kind'], $data['match'], $value, $folder);
        }

        return response()->json($r + ['moved' => $moved, 'personalOnly' => $personalOnly, 'folders' => $store->folders()]);
    }
}
