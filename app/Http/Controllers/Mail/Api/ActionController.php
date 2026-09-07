<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Models\Webmail\Label;
use App\Models\Webmail\Reminder;
use App\Models\Webmail\Snooze;
use App\Services\Mail\Charset;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Действия над набором писем: флаги, перемещения, метки, отложить, напомнить. */
class ActionController extends Controller
{
    public function store(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate([
            'folder' => ['required', 'string'],
            'uids' => ['required', 'array', 'min:1', 'max:500'],
            'uids.*' => ['integer'],
            'op' => ['required', 'string'],
            'target' => ['nullable', 'string'],
            'label' => ['nullable', 'integer'],
            'until' => ['nullable', 'date'],
        ]);

        $store = new MailStore($imap->client());
        $folder = $data['folder'];
        $uids = $data['uids'];

        switch ($data['op']) {
            case 'seen':
                $store->flag($folder, $uids, '\\Seen', true);
                break;
            case 'unseen':
                $store->flag($folder, $uids, '\\Seen', false);
                break;
            case 'flag':
                $store->flag($folder, $uids, '\\Flagged', true);
                break;
            case 'unflag':
                $store->flag($folder, $uids, '\\Flagged', false);
                break;
            case 'delete':
                $store->delete($folder, $uids);
                break;
            case 'move':
                abort_unless(! empty($data['target']), 422, 'Не указана папка');
                $store->move($folder, $uids, $data['target']);
                break;
            case 'archive':
                $store->move($folder, $uids, $store->rolePath('archive'));
                break;
            case 'spam':
                $store->flag($folder, $uids, '\\Seen', true);
                $store->move($folder, $uids, $store->rolePath('spam'));
                break;
            case 'notspam':
                $store->move($folder, $uids, $store->rolePath('inbox'));
                break;
            case 'label':
            case 'unlabel':
                $label = Label::where('user', $imap->user())->findOrFail($data['label'] ?? 0);
                $store->flag($folder, $uids, $label->keyword(), $data['op'] === 'label');
                break;
            case 'snooze':
                $this->snooze($store, $imap->user(), $folder, $uids, Carbon::parse($data['until'] ?? 'tomorrow 09:00'));
                break;
            case 'unsnooze':
                $this->unsnooze($store, $imap->user(), $folder, $uids);
                break;
            case 'remind':
                $this->remind($store, $imap->user(), $folder, $uids, Carbon::parse($data['until'] ?? '+3 days'));
                break;
            default:
                abort(422, 'Неизвестное действие');
        }

        return response()->json(['ok' => true, 'folders' => $store->folders()]);
    }

    /** Убрать из «Входящих» до срока: письмо переезжает в «Отложенные», запись — в базу. */
    private function snooze(MailStore $store, string $user, string $folder, array $uids, Carbon $until): void
    {
        $snoozed = $store->rolePath('snoozed');
        $f = $store->folder($folder);
        $movable = [];
        foreach ($uids as $uid) {
            $m = $f->query()->setFetchBody(false)->getMessageByUid((int) $uid);
            if (! $m) {
                continue;
            }
            $mid = trim((string) ($m->getMessageId()->first() ?? ''), '<>');
            if ($mid === '') {
                continue; // без Message-ID письмо не найти обратно — оставляем на месте
            }
            Snooze::create([
                'user' => $user, 'message_id' => $mid, 'origin' => $folder,
                'subject' => mb_substr((string) Charset::header((string) $m->getSubject()->first()), 0, 500), 'until' => $until,
            ]);
            $movable[] = (int) $uid;
        }
        abort_if(! $movable, 422, 'У письма нет Message-ID — его нельзя отложить');
        $store->move($folder, $movable, $snoozed);
    }

    private function unsnooze(MailStore $store, string $user, string $folder, array $uids): void
    {
        $f = $store->folder($folder);
        foreach ($uids as $uid) {
            $m = $f->query()->setFetchBody(false)->getMessageByUid((int) $uid);
            $mid = $m ? trim((string) ($m->getMessageId()->first() ?? ''), '<>') : '';
            if ($mid !== '') {
                Snooze::where('user', $user)->where('message_id', $mid)->delete();
            }
        }
        $store->move($folder, $uids, $store->rolePath('inbox'));
        $store->flag($store->rolePath('inbox'), $uids, '\\Seen', false);
    }

    /** Напомнить, если на письмо не придёт ответ. */
    private function remind(MailStore $store, string $user, string $folder, array $uids, Carbon $at): void
    {
        $f = $store->folder($folder);
        foreach ($uids as $uid) {
            $m = $f->query()->setFetchBody(false)->getMessageByUid((int) $uid);
            if (! $m) {
                continue;
            }
            $mid = trim((string) ($m->getMessageId()->first() ?? ''), '<>');
            if ($mid === '') {
                continue;
            }
            $to = collect($m->getTo()?->toArray() ?? [])->map(fn ($a) => $a->mail)->implode(', ');
            Reminder::create([
                'user' => $user, 'message_id' => $mid, 'subject' => mb_substr((string) Charset::header((string) $m->getSubject()->first()), 0, 500),
                'to' => mb_substr($to, 0, 500), 'remind_at' => $at,
            ]);
        }
    }
}
