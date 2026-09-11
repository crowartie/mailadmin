<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\FeedbackMessage;
use App\Models\FeedbackTicket;
use App\Models\Vmail\Mailbox;
use App\Services\FeedbackNotifier;
use App\Support\Area;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «Сообщить о проблеме» для сотрудника: форма в один шаг и список своих обращений.
 *
 * Форма спрашивает только то, что человек знает сам («что случилось»), остальное — страницу,
 * браузер, версию, последние ошибки на странице — снимаем автоматически: описывать это словами
 * сотрудник всё равно не сможет, а без этого обращение бесполезно.
 */
class FeedbackController extends Controller
{
    private const MAX_FILE = 8 * 1024 * 1024;

    public function index(Request $request): Response
    {
        $user = strtolower((string) $request->session()->get('mail.user'));
        $list = FeedbackTicket::query()->where('user', $user)->orderByDesc('last_reply_at')->orderByDesc('id')->limit(100)->get();
        $last = self::lastMessages($list->pluck('id')->all());
        $tickets = $list->map(fn (FeedbackTicket $t) => $this->row($t) + ['last' => $last[$t->id] ?? null])->all();

        $openId = (int) $request->query('id');
        $open = null;
        if ($openId) {
            $ticket = FeedbackTicket::query()->where('user', $user)->find($openId);
            if ($ticket) {
                $ticket->forceFill(['new_for_user' => false])->save();
                $open = $this->row($ticket) + ['messages' => $this->messages($ticket)];
            }
        }

        return Inertia::render('Mail/Feedback', [
            'user' => $user,
            'settings' => ['theme' => 'system'],
            'tickets' => $tickets,
            'open' => $open,
            'kinds' => FeedbackTicket::KINDS,
        ]);
    }

    /** Новое обращение из диалога (FormData: text, kind, subject, context, file). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:3', 'max:5000'],
            'kind' => ['required', 'in:bug,idea,question'],
            'subject' => ['nullable', 'string', 'max:200'],
            'context' => ['nullable', 'string', 'max:8000'],
            'file' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp,image/gif', 'max:8192'],
        ]);

        $user = strtolower((string) $request->session()->get('mail.user'));
        abort_if($user === '', 401);
        // Не даём завалить раздел: пять обращений в час с одного ящика — более чем достаточно.
        abort_if(FeedbackTicket::query()->where('user', $user)->where('created_at', '>=', now()->subHour())->count() >= 5, 429, 'Слишком много обращений подряд. Продолжите в уже созданном.');

        $context = json_decode((string) ($data['context'] ?? ''), true) ?: [];
        $text = trim($data['text']);

        $ticket = FeedbackTicket::create([
            'user' => $user,
            'user_name' => (string) (Mailbox::query()->where('username', $user)->value('name') ?: ''),
            'kind' => $data['kind'],
            'subject' => Str::limit(trim((string) ($data['subject'] ?? '')) ?: $this->subjectFrom($text), 200, ''),
            'status' => 'new',
            'priority' => 'normal',
            'area' => Area::isAdmin($request) ? 'admin' : 'mail',
            'page_url' => Str::limit((string) ($context['url'] ?? ''), 500, ''),
            'page_title' => Str::limit((string) ($context['page'] ?? ''), 200, ''),
            'client' => Str::limit((string) ($context['client'] ?? ''), 200, ''),
            'agent' => Str::limit((string) $request->userAgent(), 400, ''),
            'ip' => (string) $request->ip(),
            'app_version' => Str::limit((string) ($context['version'] ?? ''), 40, ''),
            'context' => array_intersect_key($context, array_flip(['screen', 'viewport', 'folder', 'errors', 'lang', 'tz'])),
            'new_for_admin' => true,
            'last_reply_at' => now(),
        ]);

        $file = $request->file('file');
        FeedbackMessage::create([
            'ticket_id' => $ticket->id,
            'author' => $user,
            'author_role' => 'user',
            'text' => $text,
            'file' => $file ? $this->storeFile($ticket, $file) : null,
        ]);

        FeedbackNotifier::toAdmins($ticket, $text);

        return response()->json(['id' => $ticket->id]);
    }

    /** Ответ сотрудника в своём обращении (в том числе на уточняющий вопрос администратора). */
    public function reply(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:5000'],
            'file' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp,image/gif', 'max:8192'],
        ]);
        $user = strtolower((string) $request->session()->get('mail.user'));
        $t = FeedbackTicket::query()->where('user', $user)->findOrFail($ticket);

        $file = $request->file('file');
        $m = FeedbackMessage::create([
            'ticket_id' => $t->id,
            'author' => $user,
            'author_role' => 'user',
            'text' => trim($data['text']),
            'file' => $file ? $this->storeFile($t, $file) : null,
        ]);
        // Ответ сотрудника снова делает обращение активным: закрытое — открываем, «ждём ответа» — в работу.
        $t->forceFill([
            'status' => $t->status === 'closed' ? 'open' : ($t->status === 'waiting' ? 'open' : $t->status),
            'resolution' => $t->status === 'closed' ? null : $t->resolution,
            'closed_at' => $t->status === 'closed' ? null : $t->closed_at,
            'new_for_admin' => true,
            'last_reply_at' => now(),
        ])->save();

        // Отдаём созданное сообщение и обращение: страница дорисовывает их сама, без перезагрузки.
        return response()->json(['message' => self::messageRow($m), 'ticket' => self::ticketRow($t->fresh())]);
    }

    /** Что нового в обращении после сообщения $after — для живого обновления открытой переписки. */
    public function poll(Request $request, int $ticket): JsonResponse
    {
        $user = strtolower((string) $request->session()->get('mail.user'));
        $t = FeedbackTicket::query()->where('user', $user)->findOrFail($ticket);
        $after = (int) $request->query('after');
        $new = FeedbackMessage::query()->where('ticket_id', $t->id)->where('id', '>', $after)->orderBy('id')->get()
            ->map(fn (FeedbackMessage $m) => self::messageRow($m))->all();
        if ($new && $t->new_for_user) {
            $t->forceFill(['new_for_user' => false])->save();
        }

        return response()->json(['messages' => $new, 'ticket' => self::ticketRow($t->fresh())]);
    }

    /** Сколько ответов сотрудник ещё не видел — для значка в рельсе (дешёвый запрос). */
    public function unread(Request $request): JsonResponse
    {
        $user = strtolower((string) $request->session()->get('mail.user'));

        return response()->json(['unread' => FeedbackTicket::query()->where('user', $user)->where('new_for_user', 1)->count()]);
    }

    /** Список обращений сотрудника — для обновления боковой колонки и значка. */
    public function listJson(Request $request): JsonResponse
    {
        $user = strtolower((string) $request->session()->get('mail.user'));
        $list = FeedbackTicket::query()->where('user', $user)->orderByDesc('last_reply_at')->orderByDesc('id')->limit(100)->get();
        $last = self::lastMessages($list->pluck('id')->all());

        return response()->json([
            'tickets' => $list->map(fn (FeedbackTicket $t) => self::ticketRow($t) + ['last' => $last[$t->id] ?? null])->all(),
            'unread' => $list->where('new_for_user', true)->count(),
        ]);
    }

    /** Снимок экрана из своего обращения. */
    public function file(Request $request, int $ticket, int $message): HttpResponse
    {
        $user = strtolower((string) $request->session()->get('mail.user'));
        $t = FeedbackTicket::query()->where('user', $user)->findOrFail($ticket);

        return self::fileResponse($t, $message);
    }

    // ── Общее с админкой ─────────────────────────────────────────────────

    public static function fileResponse(FeedbackTicket $ticket, int $messageId): HttpResponse
    {
        $m = FeedbackMessage::query()->where('ticket_id', $ticket->id)->findOrFail($messageId);
        abort_if(! $m->file, 404);
        $path = 'feedback/' . $ticket->id . '/' . $m->file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => Storage::disk('local')->mimeType($path) ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . $m->file . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Последнее сообщение каждого обращения — превью в списке переписок. @return array<int,array{text:string,at:?string,role:string}> */
    public static function lastMessages(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $out = [];
        foreach (FeedbackMessage::query()->whereIn('ticket_id', $ids)->orderBy('id')->get(['ticket_id', 'text', 'author_role', 'created_at']) as $m) {
            $out[(int) $m->ticket_id] = ['text' => \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $m->text), 90), 'at' => $m->created_at?->toIso8601String(), 'role' => $m->author_role];
        }

        return $out;
    }

    public static function messageRows(FeedbackTicket $ticket): array
    {
        return FeedbackMessage::query()->where('ticket_id', $ticket->id)->orderBy('id')->get()
            ->map(fn (FeedbackMessage $m) => self::messageRow($m))->all();
    }

    public static function messageRow(FeedbackMessage $m): array
    {
        return [
            'id' => $m->id,
            'author' => $m->author,
            'role' => $m->author_role,
            'text' => $m->text,
            'file' => (bool) $m->file,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }

    public static function ticketRow(FeedbackTicket $t): array
    {
        return [
            'id' => $t->id,
            'user' => $t->user,
            'userName' => $t->user_name,
            'kind' => $t->kind,
            'kindLabel' => FeedbackTicket::KINDS[$t->kind] ?? $t->kind,
            'subject' => $t->subject,
            'status' => $t->status,
            'statusLabel' => $t->statusLabel(),
            'resolution' => $t->resolution,
            'priority' => $t->priority,
            'duplicateOf' => $t->duplicate_of,
            'page' => $t->page_title,
            'pageUrl' => $t->page_url,
            'client' => $t->client,
            'area' => $t->area,
            'version' => $t->app_version,
            'ip' => $t->ip,
            'agent' => $t->agent,
            'context' => $t->context ?: [],
            'assignedTo' => $t->assigned_to,
            'newForAdmin' => $t->new_for_admin,
            'newForUser' => $t->new_for_user,
            'createdAt' => $t->created_at?->toIso8601String(),
            'lastReplyAt' => $t->last_reply_at?->toIso8601String(),
            'closedAt' => $t->closed_at?->toIso8601String(),
        ];
    }

    private function row(FeedbackTicket $t): array
    {
        return self::ticketRow($t);
    }

    private function messages(FeedbackTicket $t): array
    {
        return self::messageRows($t);
    }

    private function storeFile(FeedbackTicket $ticket, $file): string
    {
        $name = Str::random(16) . '.' . ($file->guessExtension() ?: 'png');
        $file->storeAs('feedback/' . $ticket->id, $name, 'local');

        return $name;
    }

    /** Тема из первой строки текста — чтобы в списке было видно, о чём обращение. */
    private function subjectFrom(string $text): string
    {
        $line = trim((string) preg_split('/\R/', $text)[0]);

        return Str::limit($line !== '' ? $line : $text, 90, '…');
    }
}
