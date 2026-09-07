<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Models\Vmail\Mailbox;
use App\Models\Webmail\Label;
use App\Models\Webmail\Setting;
use App\Services\Mail\ImapSession;
use App\Services\Vmail\MailboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(ImapSession $imap): JsonResponse
    {
        return response()->json(Setting::for($imap->user()));
    }

    public function update(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'signature' => ['nullable', 'string', 'max:20000'],
            'signature_reply' => ['nullable', 'boolean'],
            'theme' => ['nullable', 'in:light,dark,system'],
            'density' => ['nullable', 'in:normal,compact'],
            'reply_all' => ['nullable', 'boolean'],
            'undo_seconds' => ['nullable', 'integer', 'min:0', 'max:30'],
            'quick_replies' => ['nullable', 'array', 'max:8'],
            'quick_replies.*' => ['string', 'max:200'],
            'shortcuts' => ['nullable', 'boolean'],
            'preview' => ['nullable', 'boolean'],
            'show_images' => ['nullable', 'in:ask,always'],
        ]);

        return response()->json(Setting::save_($imap->user(), $data));
    }

    /** Смена пароля ящика: проверяем текущий по сессии, пишем SSHA512 в vmail, обновляем секрет сессии. */
    public function password(Request $request, ImapSession $imap, MailboxService $mailboxes): JsonResponse
    {
        $data = $request->validate([
            'current' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'max:128', 'confirmed'],
        ]);
        abort_unless(hash_equals($imap->password(), $data['current']), 422, 'Текущий пароль указан неверно');

        $mailbox = Mailbox::findOrFail($imap->user());
        $mailbox->password = $mailboxes->hashPassword($data['password']);
        $mailbox->passwordlastchange = now();
        $mailbox->save();
        $imap->rotate($data['password']);

        return response()->json(['ok' => true]);
    }

    // ── Метки ──────────────────────────────────────────────────────────

    public function labels(ImapSession $imap): JsonResponse
    {
        return response()->json(Label::where('user', $imap->user())->orderBy('sort')->orderBy('id')->get(['id', 'name', 'color']));
    }

    public function storeLabel(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        abort_if(Label::where('user', $imap->user())->count() >= 30, 422, 'Слишком много меток');
        $label = Label::create(['user' => $imap->user(), 'name' => $data['name'], 'color' => $data['color'] ?? '#2F6FEB']);

        return $this->labels($imap);
    }

    public function updateLabel(Request $request, ImapSession $imap, int $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        Label::where('user', $imap->user())->findOrFail($id)->update(array_filter($data));

        return $this->labels($imap);
    }

    public function destroyLabel(ImapSession $imap, int $id): JsonResponse
    {
        Label::where('user', $imap->user())->findOrFail($id)->delete();

        return $this->labels($imap);
    }
}
