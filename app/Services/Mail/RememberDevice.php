<?php

namespace App\Services\Mail;

use App\Models\AppSetting;
use App\Models\EmployeeProfile;
use App\Models\MailLogin;
use App\Models\MailSession;
use App\Models\Webmail\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «Не выходить на этом устройстве»: вход в веб-почту переживает конец сеанса.
 *
 * Сеанс живёт 12 часов без запросов; люди жаловались, что утром почта снова просит пароль.
 * Запомненное устройство хранит в cookie случайный ключ (selector:token), в базе — только
 * SHA-256 от токена и зашифрованный пароль ящика (как и сама сессия). Когда сеанс кончился,
 * EnsureMailSession открывает новый по этому ключу: пароль проверяется входом в IMAP, срок
 * продлевается. Токен при этом не меняется: после простоя веб-почта шлёт несколько запросов
 * разом, и смена токена первым из них выбивала бы остальные. Cookie — только https и httpOnly.
 *
 * Запись удаляют: «Выйти», «Завершить» у сеанса, «Завершить все, кроме этого», блокировка
 * входа, смена пароля и удаление ящика администратором (kickAll), неверный пароль при входе
 * по ключу. Двухфакторный код на запомненном устройстве второй раз не спрашивается.
 */
final class RememberDevice
{
    public const COOKIE = 'mail_remember';

    private const TABLE = 'webmail_remember';

    /** Сколько дней устройство помнит вход без использования; 0 — функция выключена администратором. */
    public static function days(): int
    {
        return max(0, (int) (AppSetting::group('security')['remember_days'] ?? 90));
    }

    /** Запомнить это устройство (после входа с паролем или из настроек). */
    public static function issue(Request $request, string $user, string $password): void
    {
        if (! self::days()) {
            return;
        }
        self::forget($request);
        $selector = bin2hex(random_bytes(12));
        $token = bin2hex(random_bytes(32));
        DB::table(self::TABLE)->insert([
            'user' => strtolower($user),
            'selector' => $selector,
            'token_hash' => hash('sha256', $token),
            'secret' => Crypt::encryptString($password),
            'device' => MailSession::device($request->userAgent()),
            'ip' => (string) $request->ip(),
            'session_id' => $request->session()->getId(),
            'created_at' => now(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::days()),
        ]);
        self::setCookie($selector . ':' . $token);
    }

    /**
     * Сеанса нет, но устройство запомнено — открыть сеанс заново.
     * true — сеанс открыт; false — ключа нет или он больше не годится (тогда он удалён).
     */
    public static function restore(Request $request): bool
    {
        $row = self::row($request);
        if (! $row || ! self::days()) {
            return false;
        }
        $profile = EmployeeProfile::for($row->user);
        if ($profile->exists && $profile->login_blocked) {
            self::drop($row->id);

            return false;
        }
        try {
            $request->session()->regenerate();
            ImapSession::login($request, $row->user, Crypt::decryptString($row->secret));
        } catch (\Throwable $e) {
            // Пароль сменили или ящик удалили — ключ больше не годится.
            Log::info('remember: вход по запомненному устройству не удался для ' . $row->user . ': ' . $e->getMessage());
            self::drop($row->id);
            MailLogin::record($request, $row->user, 'bad_password');

            return false;
        }
        DB::table(self::TABLE)->where('id', $row->id)->update([
            'session_id' => $request->session()->getId(),
            'ip' => (string) $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::days()),
        ]);
        self::setCookie((string) $request->cookie(self::COOKIE));
        MailSession::seen($request, $row->user);
        MailLogin::record($request, $row->user, 'remember');
        // Требование администратора включить 2FA действует и здесь — как при обычном входе.
        if ($profile->exists && $profile->require_2fa && empty(Setting::for($row->user, true)['totp_enabled'])) {
            $request->session()->put('mail.force2fa', true);
        }

        return true;
    }

    /** Это устройство запомнено (и ключ ещё годен)? */
    public static function isCurrent(Request $request): bool
    {
        return self::row($request) !== null;
    }

    /** Забыть это устройство («Выйти», выключили в настройках). */
    public static function forget(Request $request): void
    {
        if ($row = self::row($request, false)) {
            self::drop($row->id);
        }
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /** Все запомненные устройства ящика (блокировка, смена пароля) — кроме, по желанию, этого сеанса. */
    public static function revokeUser(string $user, ?string $exceptSession = null): void
    {
        DB::table(self::TABLE)->where('user', strtolower($user))
            ->when($exceptSession, fn ($q) => $q->where(fn ($w) => $w->whereNull('session_id')->orWhere('session_id', '!=', $exceptSession)))
            ->delete();
    }

    /** Запомненные устройства этих сеансов («Завершить» в списке сеансов). */
    public static function revokeSessions(array $sessionIds): void
    {
        if ($sessionIds) {
            DB::table(self::TABLE)->whereIn('session_id', $sessionIds)->delete();
        }
    }

    public static function revokeId(int $id, ?string $user = null): void
    {
        DB::table(self::TABLE)->where('id', $id)->when($user, fn ($q) => $q->where('user', strtolower($user)))->delete();
    }

    /** Запомненные устройства ящика: для списка «Где вы вошли». */
    public static function list(string $user): array
    {
        return DB::table(self::TABLE)->where('user', strtolower($user))->where('expires_at', '>', now())->orderByDesc('last_used_at')->get()->all();
    }

    /** Истёкшие ключи — из базы (ночью, вместе с остальной уборкой). */
    public static function prune(): int
    {
        return DB::table(self::TABLE)->where('expires_at', '<', now())->delete();
    }

    /** Строка по cookie; $valid — только непросроченная с верным токеном. */
    private static function row(Request $request, bool $valid = true): ?object
    {
        $raw = (string) $request->cookie(self::COOKIE, '');
        if (! preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $raw, $m)) {
            return null;
        }
        $row = DB::table(self::TABLE)->where('selector', $m[1])->first();
        if (! $row) {
            return null;
        }
        if (! $valid) {
            return $row;
        }
        if (! hash_equals($row->token_hash, hash('sha256', $m[2]))) {
            Log::warning('remember: неверный токен для ' . $row->user);

            return null;
        }

        return strtotime((string) $row->expires_at) > time() ? $row : null;
    }

    private static function drop(int $id): void
    {
        DB::table(self::TABLE)->where('id', $id)->delete();
    }

    private static function setCookie(string $value): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, $value, self::days() * 1440, '/', null, true, true, false, 'lax'));
    }
}
