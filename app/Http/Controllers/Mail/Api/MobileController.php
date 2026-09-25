<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mail\LoginController;
use App\Models\AppSetting;
use App\Models\EmployeeProfile;
use App\Models\MailLogin;
use App\Models\Webmail\Setting;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MobileDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

/**
 * Мобильное приложение: как найти сервер, вход и выход, свои устройства, адрес для push.
 * Всё остальное приложение берёт у тех же контроллеров, что и веб-почта (routes/mail-api.php под /api/v1).
 *
 * Правила входа — те же, что у веб-почты (LoginController): пределы попыток на ящик и на адрес,
 * закрытый администратором вход, код двухфакторной защиты, письмо о входе с нового устройства.
 * Пароль проверяется один раз и нигде не сохраняется; дальше — токен (MobileDevices).
 */
class MobileController extends Controller
{
    private const MAX_FAILURES = 8;
    private const MAX_IP_FAILURES = 60;
    /** Сколько живёт ожидание кода 2FA после верного пароля. */
    private const CHALLENGE_TTL = 600;
    /** Самая старая версия приложения, с которой этот сервер ещё работает. */
    public const MIN_APP = '0.1.0';

    /** GET /.well-known/mailadmin — приложение по адресу почты находит сервер и его возможности. */
    public function discover(Request $request): JsonResponse
    {
        $base = rtrim($request->getSchemeAndHttpHost(), '/');

        return response()->json([
            'api' => $base . '/api/v1',
            'name' => 'Почта ' . config('areas.default_domain'),
            'domain' => config('areas.default_domain'),
            'version' => $this->serverVersion(),
            'minApp' => self::MIN_APP,
            'features' => $this->features(),
        ])->header('Cache-Control', 'public, max-age=300');
    }

    /** POST /api/v1/login — адрес и пароль; при 2FA — 202 и challenge для /login/code. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device' => ['nullable', 'array'],
            'device.name' => ['nullable', 'string', 'max:120'],
            'device.platform' => ['nullable', 'string', 'in:android,ios,desktop'],
            'device.app_version' => ['nullable', 'string', 'max:20'],
        ]);
        $login = strtolower(trim($data['login']));
        if (! str_contains($login, '@')) {
            $login .= '@' . config('areas.default_domain');
        }

        if (MailLogin::recentUserFailures($login) >= self::MAX_FAILURES || MailLogin::recentFailures((string) $request->ip()) >= self::MAX_IP_FAILURES) {
            MailLogin::record($request, $login, 'blocked');

            return $this->fail('Слишком много попыток входа в этот ящик. Подождите 15 минут.', 'tooMany', 429);
        }
        $profile = EmployeeProfile::for($login);
        if ($profile->exists && $profile->login_blocked) {
            MailLogin::record($request, $login, 'blocked');

            return $this->fail('Вход в почту закрыт администратором. Почта продолжает приниматься.', 'blocked', 403);
        }
        try {
            ImapSession::verify($login, $data['password']);
        } catch (\Throwable) {
            MailLogin::record($request, $login, 'bad_password');

            return $this->fail('Не удалось войти: неверный адрес или пароль.', 'badPassword', 422);
        }

        $settings = Setting::for($login, true);
        $has2fa = ! empty($settings['totp_enabled']) && ! empty($settings['totp_secret']);
        if (! $has2fa && $profile->exists && $profile->require_2fa) {
            // Включить 2FA можно только в веб-почте (там показывается QR-код).
            return $this->fail('Администратор требует двухфакторную защиту: включите её в веб-почте (Настройки → Безопасность), потом войдите здесь.', 'twofaRequired', 403);
        }
        if ($has2fa) {
            $challenge = bin2hex(random_bytes(24));
            Cache::put('mobile.challenge.' . $challenge, ['user' => $login, 'device' => $data['device'] ?? []], self::CHALLENGE_TTL);

            return response()->json(['challenge' => $challenge, 'message' => 'Введите код из приложения-аутентификатора'], 202);
        }

        return $this->complete($request, $login, $data['device'] ?? []);
    }

    /** POST /api/v1/login/code — код 2FA к challenge из /login. */
    public function code(Request $request, Google2FA $google2fa): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'size:48'], 'code' => ['required', 'digits:6']]);
        $key = 'mobile.challenge.' . $data['challenge'];
        $pending = Cache::get($key);
        if (! is_array($pending)) {
            return $this->fail('Время на ввод кода вышло — войдите заново.', 'expired', 410);
        }
        if (MailLogin::recentCodeFailures($pending['user']) >= self::MAX_FAILURES) {
            Cache::forget($key);
            MailLogin::record($request, $pending['user'], 'blocked');

            return $this->fail('Слишком много неверных кодов. Войдите заново через 15 минут.', 'tooMany', 429);
        }
        $settings = Setting::for($pending['user'], true);
        $secret = ! empty($settings['totp_secret']) ? \Illuminate\Support\Facades\Crypt::decryptString($settings['totp_secret']) : null;
        if (! $secret || ! $google2fa->verifyKey($secret, $data['code'], 1)) {
            MailLogin::record($request, $pending['user'], 'bad_code');

            return $this->fail('Код не подошёл. Проверьте время на телефоне и попробуйте ещё раз.', 'badCode', 422);
        }
        Cache::forget($key);

        return $this->complete($request, $pending['user'], $pending['device'] ?? []);
    }

    /** GET /api/v1/me — кто вошёл и что умеет сервер (приложение спрашивает при запуске). */
    public function me(Request $request, ImapSession $imap): JsonResponse
    {
        $device = $request->attributes->get('mobile_device');

        return response()->json([
            'user' => $imap->user(),
            'name' => \App\Services\Mail\MailBuilder::senderName($imap->user()),
            'device' => ['id' => (int) $device->id, 'name' => $device->name, 'platform' => $device->platform],
            'server' => ['version' => $this->serverVersion(), 'minApp' => self::MIN_APP, 'features' => $this->features()],
            'tokenDays' => MobileDevices::days(),
        ]);
    }

    /** DELETE /api/v1/session — «Выйти» в приложении: токен этого устройства больше не действует. */
    public function logout(Request $request): JsonResponse
    {
        MobileDevices::revoke((int) $request->attributes->get('mobile_device')->id);

        return response()->json(['ok' => true]);
    }

    /** POST /api/v1/devices/push — адрес для уведомлений (kind=null — выключить). */
    public function push(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'string', 'in:fcm,rustore,apns'],
            'token' => ['required_with:kind', 'nullable', 'string', 'max:512'],
            'shared' => ['nullable', 'boolean'],
        ]);
        MobileDevices::setPush((int) $request->attributes->get('mobile_device')->id, $data['kind'] ?? null, $data['token'] ?? null, (bool) ($data['shared'] ?? false));

        return response()->json(['ok' => true]);
    }

    /** GET /api/v1/devices — мои устройства с приложением (для экрана «Безопасность»). */
    public function devices(Request $request, ImapSession $imap): JsonResponse
    {
        $me = (int) $request->attributes->get('mobile_device')->id;

        return response()->json(array_map(fn ($d) => [
            'id' => (int) $d->id, 'name' => $d->name, 'platform' => $d->platform, 'appVersion' => $d->app_version,
            'ip' => $d->ip, 'created' => $this->iso($d->created_at), 'seen' => $this->iso($d->last_seen_at), 'me' => (int) $d->id === $me,
        ], MobileDevices::list($imap->user())));
    }

    /** DELETE /api/v1/devices/{id} — выйти на другом своём устройстве. */
    public function revokeDevice(ImapSession $imap, int $id): JsonResponse
    {
        abort_unless(MobileDevices::revoke($id, $imap->user()) > 0, 404, 'Такого устройства нет');

        return response()->json(['ok' => true]);
    }

    /** Пароль (и код) верны — выдать токен, записать вход, предупредить о новом устройстве. */
    private function complete(Request $request, string $login, array $device): JsonResponse
    {
        $everLogged = MailLogin::query()->where('user', $login)->whereIn('result', ['ok', 'new_device'])->exists();
        [$token, $row] = MobileDevices::issue($login, $device, (string) $request->ip());
        // Каждый вход приложения — новое устройство (у него новый токен), кроме самого первого входа в почту вообще.
        MailLogin::record($request, $login, $everLogged ? 'new_device' : 'ok');
        if ($everLogged && (AppSetting::group('security')['notify_new_device'] ?? true)) {
            LoginController::notifyNewDevice($login, MobileDevices::label($row), (string) $request->ip());
        }

        return response()->json([
            'token' => $token,
            'user' => $login,
            'name' => \App\Services\Mail\MailBuilder::senderName($login),
            'device' => ['id' => (int) $row->id],
            'tokenDays' => MobileDevices::days(),
            'server' => ['version' => $this->serverVersion(), 'minApp' => self::MIN_APP, 'features' => $this->features()],
        ]);
    }

    private function fail(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }

    /** Что включено на этом сервере: приложение прячет разделы, которых нет. */
    private function features(): array
    {
        $f = ['mail', 'contacts', 'calendar', 'tasks', 'rules', 'labels'];
        try {
            if (\App\Services\Cloud\Cloud::enabled()) {
                $f[] = 'files';
            }
            if (\App\Services\Cloud\PersonalCloud::enabled()) {
                $f[] = 'cloud';
            }
        } catch (\Throwable) {
            // облако не настроено — без него
        }

        return $f;
    }

    private function serverVersion(): string
    {
        return (string) Cache::remember('app.version', 300, function () {
            $head = @file_get_contents(base_path('.git/HEAD'));
            if ($head && preg_match('#ref: (\S+)#', $head, $m)) {
                $head = @file_get_contents(base_path('.git/' . $m[1]));
            }

            return $head ? substr(trim($head), 0, 7) : 'dev';
        });
    }

    private function iso(mixed $at): ?string
    {
        return $at ? \Illuminate\Support\Carbon::parse($at)->toIso8601String() : null;
    }
}
