<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use App\Services\Mail\PushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Push-уведомления о новых письмах: подписка устройств и приём событий от Dovecot. См. PushNotifier. */
class PushController extends Controller
{
    /** Открытый ключ VAPID для браузера и признак, что служба настроена. */
    public function key(ImapSession $imap): JsonResponse
    {
        return response()->json(['enabled' => PushNotifier::enabled(), 'key' => PushNotifier::publicKey(), 'devices' => PushNotifier::devices($imap->user())]);
    }

    /** Браузер оформил подписку у своего push-сервиса и присылает её сюда. */
    public function subscribe(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => 'required|string|max:2000',
            'keys.p256dh' => 'required|string|max:200',
            'keys.auth' => 'required|string|max:64',
            'agent' => 'nullable|string|max:200',
        ]);
        $row = PushNotifier::subscribe($imap->user(), $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $data['agent'] ?? $request->userAgent());

        return response()->json(['ok' => true, 'id' => $row->id, 'devices' => PushNotifier::devices($imap->user())]);
    }

    public function unsubscribe(Request $request, ImapSession $imap): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint', '');
        $n = $endpoint !== '' ? PushNotifier::unsubscribe($imap->user(), $endpoint) : 0;

        return response()->json(['ok' => true, 'removed' => $n, 'devices' => PushNotifier::devices($imap->user())]);
    }

    /**
     * Событие от Dovecot (deploy/dovecot-push.lua): новые письма во «Входящих» сотрудника.
     * Без сеанса — по токену из .env; ходит только с самого сервера.
     */
    public function event(Request $request, PushNotifier $push): JsonResponse
    {
        $token = (string) config('areas.push.event_token');
        if ($token === '' || ! hash_equals($token, (string) $request->header('X-Push-Token', ''))) {
            abort(404);
        }
        $user = strtolower(trim((string) $request->input('user', '')));
        $messages = $request->input('messages', []);
        if ($user === '' || ! str_contains($user, '@') || ! is_array($messages) || ! $messages) {
            return response()->json(['ok' => false, 'reason' => 'нет пользователя или писем'], 422);
        }
        if (! \App\Models\Webmail\PushSubscription::query()->where('user', $user)->exists()) {
            return response()->json(['ok' => true, 'sent' => 0]);   // устройств нет — и в ящик ходить незачем
        }
        $unseen = null;
        try {
            $unseen = (int) ((new MailStore(ImapSession::master($user)))->status('INBOX')['unseen'] ?? 0);
        } catch (\Throwable $e) {
            Log::info('push: непрочитанные не посчитались (' . $user . '): ' . $e->getMessage());
        }

        return response()->json(['ok' => true, 'sent' => $push->notifyNewMail($user, $messages, $unseen)]);
    }
}
