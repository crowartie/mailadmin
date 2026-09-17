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
                $store->move($folder, $uids, $store->moveTarget($folder, $data['target']));
                break;
            case 'archive':
                $store->move($folder, $uids, $store->rolePathFor($folder, 'archive'));
                break;
            case 'spam':
                $store->flag($folder, $uids, '\\Seen', true);
                $store->move($folder, $uids, $store->rolePathFor($folder, 'spam'));
                break;
            case 'notspam':
                $store->move($folder, $uids, $store->rolePathFor($folder, 'inbox'));
                break;
            case 'lists':
                $store->move($folder, $uids, $store->rolePathFor($folder, 'lists'));
                break;
            case 'label':
            case 'unlabel':
                $label = Label::where('user', $imap->user())->findOrFail($data['label'] ?? 0);
                $store->flag($folder, $uids, $label->keyword(), $data['op'] === 'label');
                break;
            case 'snooze':
                $result = $this->snooze($store, $imap->user(), $folder, $uids, Carbon::parse($data['until'] ?? 'tomorrow 09:00'));
                break;
            case 'unsnooze':
                $result = $this->unsnooze($store, $imap->user(), $folder, $uids);
                break;
            case 'remind':
                $result = $this->remind($store, $imap->user(), $folder, $uids, Carbon::parse($data['until'] ?? '+3 days'));
                break;
            default:
                abort(422, 'Неизвестное действие');
        }

        // Частичный успех раньше выдавался за полный: из двадцати выделенных откладывалось
        // девятнадцать, и об этом не говорилось.
        return response()->json(['ok' => true, 'folders' => $store->folders()] + ($result ?? []));
    }

    /**
     * Убрать из «Входящих» до срока: письмо переезжает в «Отложенные», запись — в базу.
     *
     * @return array{done:int,skipped:int}
     */
    private function snooze(MailStore $store, string $user, string $folder, array $uids, Carbon $until): array
    {
        // «Отложить» прячет письмо из папки. В общей папке это чужие письма: они исчезли бы
        // у владельца и появились в вашем ящике. Раньше так и было — брали свою папку «Отложенные».
        abort_if(MailStore::sharedOwner($folder) !== null, 422,
            'Отложить письмо из общей папки нельзя: оно пропадёт у владельца. Перешлите его себе или поставьте напоминание.');

        $snoozed = $store->rolePath('snoozed');
        $ids = self::messageIds($store, $folder, $uids);
        $movable = array_keys($ids);
        abort_if(! $movable, 422, 'У письма нет Message-ID — его нельзя отложить');

        // Сначала переносим, и только потом пишем в базу: при сбое переноса записи
        // оставались, и планировщик считал письмо отложенным, хотя оно лежало во «Входящих».
        $store->move($folder, $movable, $snoozed);
        foreach ($ids as $uid => $row) {
            Snooze::create([
                'user' => $user, 'message_id' => $row['mid'], 'origin' => $folder,
                'subject' => mb_substr($row['subject'], 0, 500), 'until' => $until,
            ]);
        }

        return ['done' => count($movable), 'skipped' => count($uids) - count($movable)];
    }

    /** @return array{done:int,skipped:int} */
    private function unsnooze(MailStore $store, string $user, string $folder, array $uids): array
    {
        $ids = self::messageIds($store, $folder, $uids);
        foreach ($ids as $row) {
            Snooze::where('user', $user)->where('message_id', $row['mid'])->delete();
        }
        // Пометку снимаем ДО переноса: после него номера писем меняются, и снятие отметки
        // по старым номерам попадало в чужие письма во «Входящих».
        $store->flag($folder, $uids, '\\Seen', false);
        $store->move($folder, $uids, $store->rolePath('inbox'));

        return ['done' => count($uids), 'skipped' => 0];
    }

    /**
     * Message-ID и темы пачкой: раньше на каждое письмо шло отдельное обращение к почтовому
     * серверу, и на выделении в пятьсот писем запрос подвисал.
     *
     * @return array<int,array{mid:string,subject:string}>
     */
    private static function messageIds(MailStore $store, string $folder, array $uids): array
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        if (! $uids) {
            return [];
        }
        $out = [];
        foreach (array_chunk($uids, 200) as $chunk) {
            foreach ($store->folder($folder)->query()->whereUidIn($chunk)->setFetchBody(false)->setFetchFlags(false)->get() as $m) {
                $mid = trim((string) ($m->getMessageId()->first() ?? ''), '<>');
                if ($mid === '') {
                    continue; // без Message-ID письмо не найти обратно — оставляем на месте
                }
                $out[(int) $m->getUid()] = ['mid' => $mid, 'subject' => (string) Charset::header((string) $m->getSubject()->first())];
            }
        }

        return $out;
    }

    /**
     * Напомнить, если на письмо не придёт ответ.
     *
     * @return array{done:int,skipped:int}
     */
    private function remind(MailStore $store, string $user, string $folder, array $uids, Carbon $at): array
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        $done = 0;
        foreach (array_chunk($uids, 200) as $chunk) {
            foreach ($store->folder($folder)->query()->whereUidIn($chunk)->setFetchBody(false)->setFetchFlags(false)->get() as $m) {
                $mid = trim((string) ($m->getMessageId()->first() ?? ''), '<>');
                if ($mid === '') {
                    continue;
                }
                $to = collect($m->getTo()?->toArray() ?? [])->map(fn ($a) => $a->mail)->implode(', ');
                Reminder::create([
                    'user' => $user, 'message_id' => $mid, 'subject' => mb_substr((string) Charset::header((string) $m->getSubject()->first()), 0, 500),
                    'to' => mb_substr($to, 0, 500), 'remind_at' => $at,
                ]);
                $done++;
            }
        }
        // Без Message-ID напоминание не поставить — раньше об этом не говорилось вовсе,
        // а ответ всё равно был успешным.
        abort_if($done === 0, 422, 'Напоминание поставить не удалось: у письма нет Message-ID');

        return ['done' => $done, 'skipped' => count($uids) - $done];
    }
}
