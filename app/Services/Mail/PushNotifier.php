<?php

namespace App\Services\Mail;

use App\Models\Webmail\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/**
 * Push-уведомления о новых письмах (Web Push): браузер на компьютере и веб-приложение на экране
 * «Домой» телефона получают их, даже когда почта закрыта.
 *
 * Как устроено: сотрудник в настройках включает уведомления → браузер оформляет подписку у своего
 * push-сервиса (Apple, Google, Mozilla) и присылает её сюда (subscribe). Dovecot при доставке письма
 * во «Входящие» зовёт deploy/dovecot-push.lua → POST /mail/api/push/event с токеном из .env →
 * notifyNewMail шлёт уведомление на все устройства человека. Ключи VAPID — в .env (push:keys),
 * они подписывают запросы к push-сервисам; без них служба выключена и всё остальное работает как раньше.
 */
class PushNotifier
{
    public const MAX_FAILURES = 8;

    public static function enabled(): bool
    {
        return (string) config('areas.push.public') !== '' && (string) config('areas.push.private') !== '';
    }

    public static function publicKey(): string
    {
        return (string) config('areas.push.public');
    }

    /** Подписка устройства: повтор с тем же адресом — обновление ключей, а не вторая запись. */
    public static function subscribe(string $user, string $endpoint, string $p256dh, string $auth, ?string $agent = null): PushSubscription
    {
        $user = strtolower($user);
        $endpoint = trim($endpoint);
        if ($endpoint === '' || ! preg_match('#^https://#i', $endpoint) || $p256dh === '' || $auth === '') {
            throw \App\Exceptions\MailException::invalid('Подписка на уведомления неполная — обновите страницу и попробуйте ещё раз');
        }
        // Адрес закреплён за устройством: если он был у другого сотрудника (вошли под другим именем в том же
        // браузере), подписка переходит к новому — старому эти уведомления уже не нужны.
        $row = PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            ['user' => $user, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'agent' => $agent ? mb_substr($agent, 0, 200) : null, 'failures' => 0, 'created_at' => now()],
        );

        return $row;
    }

    public static function unsubscribe(string $user, string $endpoint): int
    {
        return PushSubscription::query()->where('user', strtolower($user))->where('endpoint_hash', hash('sha256', trim($endpoint)))->delete();
    }

    /** Устройства сотрудника — для списка в настройках. */
    public static function devices(string $user): array
    {
        return PushSubscription::query()->where('user', strtolower($user))->orderBy('created_at')->get()
            ->map(fn ($s) => ['id' => $s->id, 'agent' => $s->agent ?: 'устройство', 'since' => $s->created_at?->toIso8601String(), 'lastSent' => $s->last_sent_at?->toIso8601String()])
            ->all();
    }

    /**
     * Текст уведомления по новым письмам: одно письмо — отправитель и тема, несколько — счётчик.
     * @param array<int,array{uid?:int,from?:string,subject?:string,snippet?:string}> $messages
     * @return array{title:string,body:string,tag:string,url:string,unseen?:int}
     */
    public static function payload(array $messages, ?int $unseen = null): array
    {
        $messages = array_values(array_filter($messages, 'is_array'));
        $n = count($messages);
        if ($n === 1) {
            $m = $messages[0];
            // Dovecot отдаёт заголовки как в письме — в MIME-кодировке; на экране телефона было «=?UTF-8?B?…».
            $from = trim((string) Charset::header((string) ($m['from'] ?? '')));
            $subject = trim((string) Charset::header((string) ($m['subject'] ?? '')));
            $out = [
                'title' => $from !== '' ? mb_substr(self::fromName($from), 0, 80) : 'Новое письмо',
                'body' => mb_substr($subject !== '' ? $subject : '(без темы)', 0, 160),
                'tag' => 'mail-' . (int) ($m['uid'] ?? 0),
                'url' => '/mail' . (! empty($m['uid']) ? '?uid=' . (int) $m['uid'] : ''),
            ];
        } else {
            $names = array_values(array_unique(array_filter(array_map(fn ($m) => self::fromName((string) Charset::header((string) ($m['from'] ?? ''))), $messages))));
            $out = [
                'title' => $n . ' ' . self::plural($n, 'новое письмо', 'новых письма', 'новых писем'),
                'body' => mb_substr(implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' и ещё ' . (count($names) - 3) : ''), 0, 160),
                'tag' => 'mail-batch',
                'url' => '/mail',
            ];
        }
        if ($unseen !== null) {
            $out['unseen'] = max(0, $unseen);
        }

        return $out;
    }

    /**
     * Новые письма у сотрудника — разослать на его устройства. Возвращает число доставленных.
     * Отказавшие подписки (устройство удалило приложение, срок истёк) убираются.
     */
    public function notifyNewMail(string $user, array $messages, ?int $unseen = null): int
    {
        $user = strtolower($user);
        $subs = PushSubscription::query()->where('user', $user)->get();
        if ($subs->isEmpty() || ! self::enabled()) {
            return 0;
        }

        return $this->send($subs->all(), self::payload($messages, $unseen));
    }

    /** Отправить одно и то же уведомление на список подписок. Отдельно, чтобы тесты подменяли доставку. */
    public function send(array $subscriptions, array $payload): int
    {
        try {
            $push = new WebPush([
                'VAPID' => [
                    'subject' => (string) config('areas.push.subject'),
                    'publicKey' => (string) config('areas.push.public'),
                    'privateKey' => (string) config('areas.push.private'),
                ],
            ], ['TTL' => 3600, 'urgency' => 'high']);
        } catch (\Throwable $e) {
            Log::warning('push: ключи VAPID не годятся: ' . $e->getMessage());

            return 0;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $byEndpoint = [];
        foreach ($subscriptions as $s) {
            $byEndpoint[$s->endpoint] = $s;
            try {
                $push->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->p256dh, 'authToken' => $s->auth, 'contentEncoding' => 'aes128gcm']), $json);
            } catch (\Throwable $e) {
                Log::warning('push: подписка ' . $s->id . ' не годится: ' . $e->getMessage());
            }
        }
        $ok = 0;
        foreach ($push->flush() as $report) {
            $s = $byEndpoint[$report->getEndpoint()] ?? null;
            if (! $s) {
                continue;
            }
            if ($report->isSuccess()) {
                $ok++;
                $s->forceFill(['failures' => 0, 'last_sent_at' => now()])->save();
                continue;
            }
            if ($report->isSubscriptionExpired() || $s->failures + 1 >= self::MAX_FAILURES) {
                $s->delete();   // устройство больше не слушает — тихо забываем
            } else {
                $s->forceFill(['failures' => $s->failures + 1])->save();
                Log::info('push: не доставлено (' . $s->user . '): ' . mb_substr((string) $report->getReason(), 0, 200));
            }
        }

        return $ok;
    }

    /** Новая пара ключей VAPID — для команды push:keys. @return array{public:string,private:string} */
    public static function makeKeys(): array
    {
        $k = VAPID::createVapidKeys();

        return ['public' => $k['publicKey'], 'private' => $k['privateKey']];
    }

    /** «Иванов Иван <ivan@x>» → «Иванов Иван», «ivan@x» → как есть. */
    public static function fromName(string $from): string
    {
        $from = trim($from);
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/u', $from, $m)) {
            return trim($m[1]) !== '' ? trim($m[1]) : trim($m[2]);
        }

        return $from;
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        if ($n > 10 && $n < 20) {
            return $many;
        }

        return match ($n % 10) {
            1 => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    }
}
