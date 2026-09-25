<?php

namespace App\Services\Mail;

use App\Models\AppSetting;
use Illuminate\Support\Facades\DB;

/**
 * Устройства мобильного приложения: вход по токену вместо сессии (docs/mobile-api.md).
 *
 * Токен — 256 случайных бит, у телефона; в базе только SHA-256 от него. Пароль ящика не хранится
 * нигде: он проверяется один раз при входе, дальше сервер ходит в ящик служебным входом Dovecot
 * (user*master), как «войти как сотрудник» в админке. Поэтому токен обязан отзываться вместе
 * со сменой пароля, блокировкой входа и удалением ящика (Sessions::kickAll) — иначе телефон
 * продолжал бы работать с уже недействительным паролем.
 *
 * Токен перестаёт действовать после days() дней без запросов; отозванное устройство удаляется.
 */
final class MobileDevices
{
    private const TABLE = 'mobile_devices';

    /** Реже раза в минуту «последний запрос» не обновляем: приложение шлёт запросы пачками. */
    private const TOUCH_EVERY = 60;

    /** Сколько дней вход на телефоне живёт без использования. */
    public static function days(): int
    {
        return max(1, (int) (AppSetting::group('security')['app_token_days'] ?? 90));
    }

    /**
     * Выдать токен новому устройству. Возвращает [токен, запись].
     *
     * @param  array{name?:string,platform?:string,app_version?:string}  $device
     */
    public static function issue(string $user, array $device, string $ip): array
    {
        $token = bin2hex(random_bytes(32));
        $id = DB::table(self::TABLE)->insertGetId([
            'user' => strtolower($user),
            'token_hash' => hash('sha256', $token),
            'name' => mb_substr(trim((string) ($device['name'] ?? '')), 0, 120),
            'platform' => mb_substr(strtolower(trim((string) ($device['platform'] ?? ''))), 0, 20),
            'app_version' => mb_substr(trim((string) ($device['app_version'] ?? '')), 0, 20),
            'ip' => $ip,
            'created_at' => now(),
            'last_seen_at' => now(),
        ]);

        return [$token, DB::table(self::TABLE)->find($id)];
    }

    /** Устройство по токену из заголовка; null — токена нет, он чужой или истёк (истёкший удаляется). */
    public static function find(?string $token): ?object
    {
        if (! is_string($token) || ! preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $row = DB::table(self::TABLE)->where('token_hash', hash('sha256', $token))->first();
        if (! $row) {
            return null;
        }
        if ($row->last_seen_at && strtotime((string) $row->last_seen_at) < time() - self::days() * 86400) {
            self::revoke((int) $row->id);

            return null;
        }

        return $row;
    }

    /** Отметить запрос с устройства (адрес, версия приложения) — не чаще раза в минуту. */
    public static function touch(object $row, string $ip, ?string $appVersion = null): void
    {
        $stale = ! $row->last_seen_at || strtotime((string) $row->last_seen_at) < time() - self::TOUCH_EVERY;
        $changed = $row->ip !== $ip || ($appVersion !== null && $appVersion !== '' && $appVersion !== $row->app_version);
        if (! $stale && ! $changed) {
            return;
        }
        $upd = ['last_seen_at' => now(), 'ip' => $ip];
        if ($appVersion !== null && $appVersion !== '') {
            $upd['app_version'] = mb_substr($appVersion, 0, 20);
        }
        DB::table(self::TABLE)->where('id', $row->id)->update($upd);
    }

    /** Адрес для push-уведомлений этого устройства (или снять его: kind = null). */
    public static function setPush(int $id, ?string $kind, ?string $token, bool $shared): void
    {
        DB::table(self::TABLE)->where('id', $id)->update([
            'push_kind' => $kind,
            'push_token' => $kind ? $token : null,
            'push_shared' => $shared,
        ]);
    }

    public static function revoke(int $id, ?string $user = null): int
    {
        return DB::table(self::TABLE)->where('id', $id)->when($user, fn ($q) => $q->where('user', strtolower($user)))->delete();
    }

    /** Все устройства ящика: смена пароля, блокировка, удаление ящика, «завершить все сеансы». */
    public static function revokeUser(string $user, ?int $except = null): int
    {
        return DB::table(self::TABLE)->where('user', strtolower($user))->when($except, fn ($q) => $q->where('id', '!=', $except))->delete();
    }

    /** Устройства ящика — для списка «Где вы вошли» и админки. */
    public static function list(string $user): array
    {
        return DB::table(self::TABLE)->where('user', strtolower($user))
            ->where('last_seen_at', '>=', now()->subDays(self::days()))->orderByDesc('last_seen_at')->get()->all();
    }

    /** Давно не использованные — из базы (ночью, с остальной уборкой). */
    public static function prune(): int
    {
        return DB::table(self::TABLE)->where('last_seen_at', '<', now()->subDays(self::days()))->delete();
    }

    /** Подпись устройства в списках: «Приложение · Pixel 9, Android 15». */
    public static function label(object $row): string
    {
        return 'Приложение · ' . ($row->name !== '' ? $row->name : ($row->platform !== '' ? ucfirst($row->platform) : 'телефон'));
    }
}
