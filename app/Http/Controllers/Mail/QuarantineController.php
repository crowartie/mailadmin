<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Webmail\Setting;
use App\Services\Mail\ImapSession;
use App\Services\Server\Quarantine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Карантин сотрудника в веб-почте: список задержанных писем, «Доставить» и «Удалить», выпуск по ссылке из сводки. */
class QuarantineController extends Controller
{
    public function __construct(private readonly Quarantine $quarantine)
    {
    }

    public function index(ImapSession $imap): \Inertia\Response
    {
        return Inertia::render('Mail/Quarantine', [
            'user' => $imap->user(),
            'settings' => Setting::for($imap->user()),
            'items' => $this->items($imap->user()),
        ]);
    }

    public function list(ImapSession $imap): JsonResponse
    {
        return response()->json($this->items($imap->user()));
    }

    /** Сколько задержано за последние 14 дней (для счётчика в меню). */
    public static function count(string $user): int
    {
        return (int) Cache::remember('quarantine.count.' . $user, 60, function () use ($user) {
            try {
                return DB::connection('amavisd')->table('msgs')->join('msgrcpt', 'msgrcpt.mail_id', '=', 'msgs.mail_id')->join('maddr', 'maddr.id', '=', 'msgrcpt.rid')
                    ->whereIn('msgs.quar_type', ['Q', 'F', 'Z'])->where('msgrcpt.rs', '!=', 'R')->where('maddr.email', strtolower($user))->count();
            } catch (\Throwable) {
                return 0;
            }
        });
    }

    public function release(Request $request, ImapSession $imap, string $id): JsonResponse
    {
        $secret = $this->secretFor($imap->user(), $id);
        abort_if(! $secret, 404, 'Письмо не найдено в вашем карантине');
        try {
            $this->quarantine->release($id, $secret);
        } catch (\RuntimeException $e) {
            abort(500, 'Не доставлено: ' . mb_substr($e->getMessage(), 0, 200));
        }
        Cache::forget('quarantine.count.' . $imap->user());

        return response()->json(['ok' => true, 'items' => $this->items($imap->user())]);
    }

    public function destroy(ImapSession $imap, string $id): JsonResponse
    {
        abort_if(! $this->secretFor($imap->user(), $id), 404, 'Письмо не найдено в вашем карантине');
        $this->quarantine->delete($id);
        Cache::forget('quarantine.count.' . $imap->user());

        return response()->json(['ok' => true, 'items' => $this->items($imap->user())]);
    }

    /** Выпуск по подписанной ссылке из письма-сводки (без входа). */
    public function releaseSigned(Request $request, string $id, string $secret): Response
    {
        $row = DB::connection('amavisd')->table('msgs')->where('mail_id', $id)->where('secret_id', $secret)->first(['mail_id']);
        $ok = false;
        $msg = 'Ссылка устарела или письмо уже удалено из карантина.';
        if ($row) {
            try {
                $this->quarantine->release($id, $secret);
                $ok = true;
                $msg = 'Письмо доставлено в ваш ящик — проверьте «Входящие».';
            } catch (\Throwable $e) {
                $msg = 'Не удалось доставить: ' . mb_substr($e->getMessage(), 0, 200);
            }
        }
        Cache::flush();
        $title = $ok ? 'Доставлено' : 'Не получилось';

        return response('<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title></head><body style="font-family:sans-serif;background:#f4f6f8;margin:0;display:flex;align-items:center;justify-content:center;height:100vh"><div style="background:#fff;padding:32px 40px;border-radius:12px;max-width:460px;box-shadow:0 8px 30px -12px rgba(0,0,0,.25)"><h1 style="margin:0 0 10px;font-size:20px">' . $title . '</h1><p style="margin:0 0 18px;color:#444">' . htmlspecialchars($msg) . '</p><a href="/mail" style="color:#1a56db">Открыть веб-почту</a></div></body></html>', $ok ? 200 : 410);
    }

    /** @return array<int,array<string,mixed>> */
    private function items(string $user): array
    {
        try {
            return array_values(array_filter($this->quarantine->list(200, $user), fn ($i) => ! $i['released']));
        } catch (\Throwable) {
            return [];
        }
    }

    private function secretFor(string $user, string $id): ?string
    {
        foreach ($this->items($user) as $i) {
            if ($i['id'] === $id) {
                return $i['secret'];
            }
        }

        return null;
    }
}
