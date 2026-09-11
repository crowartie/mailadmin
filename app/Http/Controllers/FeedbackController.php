<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Mail\FeedbackController as UserFeedback;
use App\Models\AdminAction;
use App\Models\FeedbackMessage;
use App\Models\FeedbackTicket;
use App\Services\FeedbackNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «Обращения» — раздел админки: заявки сотрудников об ошибках, предложения и вопросы.
 *
 * Состояние и итог разделены, как в трекерах: status показывает, где обращение в работе,
 * resolution — чем оно закончилось (исправлено, не ошибка, не будем исправлять, повтор).
 * Поэтому закрыть можно только с указанием итога, и в списке всегда видно, почему закрыли.
 */
class FeedbackController extends Controller
{
    private const FILTERS = ['active' => 'В работе', 'new' => 'Новые', 'waiting' => 'Ждут ответа', 'closed' => 'Закрытые', 'all' => 'Все'];

    public function index(Request $request): Response
    {
        $filter = (string) $request->query('filter', 'active');
        if (! isset(self::FILTERS[$filter])) {
            $filter = 'active';
        }
        $search = trim((string) $request->query('search', ''));

        $rows = FeedbackTicket::query()
            ->when($filter === 'active', fn ($q) => $q->whereIn('status', ['new', 'open']))
            ->when($filter === 'new', fn ($q) => $q->where('status', 'new'))
            ->when($filter === 'waiting', fn ($q) => $q->where('status', 'waiting'))
            ->when($filter === 'closed', fn ($q) => $q->where('status', 'closed'))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('subject', 'like', '%' . $search . '%')
                ->orWhere('user', 'like', '%' . $search . '%')
                ->orWhere('user_name', 'like', '%' . $search . '%')
                ->orWhere('id', (int) ltrim($search, '#'))))
            ->orderByRaw("FIELD(status,'new','open','waiting','closed')")
            ->orderByDesc('last_reply_at')
            ->limit(300)->get()
            ->map(fn (FeedbackTicket $t) => UserFeedback::ticketRow($t))->all();

        $open = null;
        if ($id = (int) $request->query('id')) {
            if ($t = FeedbackTicket::query()->find($id)) {
                if ($t->new_for_admin) {
                    $t->forceFill(['new_for_admin' => false])->save();
                }
                $open = UserFeedback::ticketRow($t) + ['messages' => UserFeedback::messageRows($t)];
            }
        }

        return Inertia::render('Feedback/Index', [
            'rows' => $rows,
            'open' => $open,
            'filter' => $filter,
            'search' => $search,
            'filters' => self::FILTERS,
            'counts' => [
                'new' => FeedbackTicket::query()->where('status', 'new')->count(),
                'active' => FeedbackTicket::query()->whereIn('status', ['new', 'open'])->count(),
                'waiting' => FeedbackTicket::query()->where('status', 'waiting')->count(),
                'closed' => FeedbackTicket::query()->where('status', 'closed')->count(),
            ],
            'dict' => [
                'kinds' => FeedbackTicket::KINDS,
                'statuses' => FeedbackTicket::STATUSES,
                'resolutions' => FeedbackTicket::RESOLUTIONS,
                'priorities' => FeedbackTicket::PRIORITIES,
            ],
            'me' => $request->user()?->email,
        ]);
    }

    /** Взять в работу, сменить важность, назначить. */
    public function update(Request $request, int $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:new,open,waiting'],
            'priority' => ['nullable', 'in:low,normal,high'],
            'assign' => ['nullable', 'boolean'],
        ]);
        $t = FeedbackTicket::findOrFail($ticket);
        $changes = [];
        if (! empty($data['status'])) {
            $t->status = $data['status'];
            $t->resolution = null;
            $t->closed_at = null;
            $changes[] = 'состояние: ' . (FeedbackTicket::STATUSES[$data['status']] ?? $data['status']);
        }
        if (! empty($data['priority'])) {
            $t->priority = $data['priority'];
            $changes[] = 'важность: ' . (FeedbackTicket::PRIORITIES[$data['priority']] ?? $data['priority']);
        }
        if (array_key_exists('assign', $data)) {
            $t->assigned_to = $data['assign'] ? $request->user()?->email : null;
            $changes[] = $data['assign'] ? 'взято в работу' : 'снято с исполнителя';
        }
        $t->new_for_admin = false;
        $t->save();
        AdminAction::log('feedback.update', '#' . $t->id, implode(', ', $changes));

        return back()->with('success', 'Обращение №' . $t->id . ' обновлено');
    }

    /** Ответ администратора. С «ask» обращение переходит в «ждём ответа сотрудника». */
    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:5000'],
            'ask' => ['nullable', 'boolean'],
        ]);
        $t = FeedbackTicket::findOrFail($ticket);
        $this->addMessage($t, $request, trim($data['text']));
        $t->forceFill([
            'status' => ! empty($data['ask']) ? 'waiting' : ($t->status === 'closed' ? 'closed' : 'open'),
            'assigned_to' => $t->assigned_to ?: $request->user()?->email,
            'new_for_admin' => false,
            'new_for_user' => true,
            'last_reply_at' => now(),
        ])->save();

        FeedbackNotifier::toUser($t, $data['text']);
        AdminAction::log('feedback.reply', '#' . $t->id, ! empty($data['ask']) ? 'уточнение' : 'ответ');

        return back()->with('success', 'Ответ отправлен сотруднику');
    }

    /** Закрыть с итогом: исправлено, не ошибка, не будем исправлять, повтор. */
    public function close(Request $request, int $ticket): RedirectResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'in:done,not_a_bug,wont_fix,duplicate'],
            'text' => ['nullable', 'string', 'max:5000'],
            'duplicate_of' => ['nullable', 'integer', 'min:1'],
        ]);
        $t = FeedbackTicket::findOrFail($ticket);
        $label = FeedbackTicket::RESOLUTIONS[$data['resolution']];
        $note = trim((string) ($data['text'] ?? ''));

        $line = $label . ($data['resolution'] === 'duplicate' && ! empty($data['duplicate_of']) ? ' — то же, что в обращении №' . (int) $data['duplicate_of'] : '');
        $this->addMessage($t, $request, $note !== '' ? $line . ".\n\n" . $note : $line, 'system');

        $t->forceFill([
            'status' => 'closed',
            'resolution' => $data['resolution'],
            'duplicate_of' => $data['resolution'] === 'duplicate' ? ($data['duplicate_of'] ?? null) : null,
            'closed_at' => now(),
            'closed_by' => $request->user()?->email,
            'assigned_to' => $t->assigned_to ?: $request->user()?->email,
            'new_for_admin' => false,
            'new_for_user' => true,
            'last_reply_at' => now(),
        ])->save();

        FeedbackNotifier::toUser($t, 'Ваше обращение закрыто: ' . mb_strtolower($label) . ".\n\n" . ($note !== '' ? $note : 'Если проблема осталась, ответьте в обращении — оно снова откроется.'));
        AdminAction::log('feedback.close', '#' . $t->id, $label);

        return back()->with('success', 'Обращение №' . $t->id . ' закрыто: ' . mb_strtolower($label));
    }

    /** Вернуть в работу закрытое обращение. */
    public function reopen(Request $request, int $ticket): RedirectResponse
    {
        $t = FeedbackTicket::findOrFail($ticket);
        $t->forceFill(['status' => 'open', 'resolution' => null, 'closed_at' => null, 'closed_by' => null, 'last_reply_at' => now()])->save();
        AdminAction::log('feedback.reopen', '#' . $t->id);

        return back()->with('success', 'Обращение №' . $t->id . ' снова в работе');
    }

    public function destroy(Request $request, int $ticket): RedirectResponse
    {
        $t = FeedbackTicket::findOrFail($ticket);
        \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory('feedback/' . $t->id);
        $t->delete();
        AdminAction::log('feedback.delete', '#' . $ticket);

        return redirect('/feedback')->with('success', 'Обращение №' . $ticket . ' удалено');
    }

    public function file(Request $request, int $ticket, int $message): HttpResponse
    {
        return UserFeedback::fileResponse(FeedbackTicket::findOrFail($ticket), $message);
    }

    private function addMessage(FeedbackTicket $t, Request $request, string $text, string $role = 'admin'): void
    {
        FeedbackMessage::create([
            'ticket_id' => $t->id,
            'author' => (string) ($request->user()?->email ?? 'админ'),
            'author_role' => $role,
            'text' => Str::limit($text, 5000, ''),
        ]);
    }
}
