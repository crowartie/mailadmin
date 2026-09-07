<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Models\Webmail\Outbox;
use App\Models\Webmail\Reminder;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use App\Services\Mail\Outgoing;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ComposeController extends Controller
{
    private const RULES = [
        'from' => ['nullable', 'email'],
        'to' => ['nullable', 'string', 'max:5000'],
        'cc' => ['nullable', 'string', 'max:5000'],
        'bcc' => ['nullable', 'string', 'max:5000'],
        'subject' => ['nullable', 'string', 'max:998'],
        'html' => ['nullable', 'string', 'max:2000000'],
        'inReplyTo' => ['nullable', 'string', 'max:998'],
        'references' => ['nullable', 'string', 'max:5000'],
        'answeredFolder' => ['nullable', 'string'],
        'answeredUid' => ['nullable', 'integer'],
        'sourceFolder' => ['nullable', 'string'],
        'sourceUid' => ['nullable', 'integer'],
        'keepAttachments' => ['nullable', 'boolean'],
        'draftUid' => ['nullable', 'integer'],
        'priority' => ['nullable', 'boolean'],
        'receipt' => ['nullable', 'boolean'],
        'sendAt' => ['nullable', 'date'],
        'remindDays' => ['nullable', 'integer', 'min:1', 'max:60'],
        'files' => ['nullable', 'array', 'max:20'],
        'files.*' => ['file', 'max:51200'],
    ];

    /** Отправить сейчас или (с sendAt) положить в очередь на отправку по расписанию. */
    public function send(Request $request, ImapSession $imap): JsonResponse
    {
        $form = $request->validate(self::RULES);
        abort_if(trim((string) ($form['to'] ?? '') . ($form['cc'] ?? '') . ($form['bcc'] ?? '')) === '', 422, 'Укажите хотя бы одного получателя');

        $store = new MailStore($imap->client());
        $out = new Outgoing($imap, $store);
        $email = $out->build($form, $request->file('files', []));

        if (! empty($form['sendAt'])) {
            $at = Carbon::parse($form['sendAt']);
            abort_if($at->isPast(), 422, 'Время отправки уже прошло');
            $path = 'outbox/' . $imap->user() . '/' . uniqid('', true) . '.eml';
            Storage::disk('local')->put($path, $email->toString());
            $recipients = array_map(fn ($a) => $a->getAddress(), array_merge($email->getTo(), $email->getCc(), $email->getBcc()));
            $row = Outbox::create([
                'user' => $imap->user(), 'from' => $email->getFrom()[0]->getAddress(), 'recipients' => $recipients,
                'subject' => $email->getSubject(), 'path' => $path, 'send_at' => $at,
            ]);
            // Черновик удаляем сразу: письмо теперь живёт в очереди.
            if (! empty($form['draftUid'])) {
                $drafts = $store->rolePath('drafts');
                $store->flag($drafts, [(int) $form['draftUid']], '\\Deleted', true);
                $store->client()->openFolder($drafts, true);
                $store->client()->getConnection()->expunge();
            }

            return response()->json(['scheduled' => $row->id, 'sendAt' => $at->toIso8601String()]);
        }

        $messageId = $out->send($email, $form);

        if (! empty($form['remindDays'])) {
            Reminder::create([
                'user' => $imap->user(), 'message_id' => $messageId, 'subject' => $email->getSubject(),
                'to' => implode(', ', array_map(fn ($a) => $a->getAddress(), $email->getTo())),
                'remind_at' => now()->addDays((int) $form['remindDays'])->setTime(9, 0),
            ]);
        }

        return response()->json(['sent' => true, 'messageId' => $messageId, 'folders' => $store->folders()]);
    }

    public function draft(Request $request, ImapSession $imap): JsonResponse
    {
        $form = $request->validate(self::RULES);
        $store = new MailStore($imap->client());
        $out = new Outgoing($imap, $store);
        $email = $out->build($form, $request->file('files', []));
        $uid = $out->saveDraft($email, ! empty($form['draftUid']) ? (int) $form['draftUid'] : null);

        return response()->json(['draftUid' => $uid, 'folder' => $store->rolePath('drafts')]);
    }

    /** Черновик → форма «Написать». */
    public function openDraft(ImapSession $imap, int $uid): JsonResponse
    {
        $store = new MailStore($imap->client());
        $m = $store->message($store->rolePath('drafts'), $uid, false);

        return response()->json([
            'draftUid' => $uid,
            'to' => $this->join($m['to']),
            'cc' => $this->join($m['cc']),
            'subject' => $m['subject'] === '(без темы)' ? '' : $m['subject'],
            'html' => $m['html'] ?? nl2br(e((string) $m['text'])),
            'inReplyTo' => $m['inReplyTo'],
            'references' => $m['references'],
            'attachments' => $m['attachments'],
        ]);
    }

    public function outbox(ImapSession $imap): JsonResponse
    {
        return response()->json(Outbox::where('user', $imap->user())->orderBy('send_at')->get(['id', 'subject', 'recipients', 'send_at', 'status', 'error']));
    }

    /** Отменить отправку по расписанию: письмо возвращается в черновики. */
    public function cancel(ImapSession $imap, int $id): JsonResponse
    {
        $row = Outbox::where('user', $imap->user())->where('status', 'scheduled')->findOrFail($id);
        $store = new MailStore($imap->client());
        $raw = Storage::disk('local')->get($row->path);
        if ($raw) {
            $store->append($store->rolePath('drafts'), $raw, ['\\Seen', '\\Draft']);
        }
        Storage::disk('local')->delete($row->path);
        $row->delete();

        return response()->json(['ok' => true]);
    }

    private function join(array $list): string
    {
        return implode(', ', array_map(fn ($a) => $a['name'] && $a['name'] !== $a['mail'] ? "{$a['name']} <{$a['mail']}>" : $a['mail'], $list));
    }
}
