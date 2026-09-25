<?php

namespace App\Http\Middleware;

use App\Models\EmployeeProfile;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MobileDevices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Вход мобильного приложения: «Authorization: Bearer <токен>» → ящик → сеанс на время запроса.
 *
 * Сеанс живёт в памяти одного запроса (ImapSession::forApp): все контроллеры веб-почты работают
 * как есть, получая тот же ImapSession. Правила те же, что у веб-почты: закрытый администратором
 * вход и требование двухфакторной защиты действуют и здесь. Ошибки — JSON с понятным текстом,
 * 401 значит «токен больше не годится, войдите заново».
 */
class MobileToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = MobileDevices::find($request->bearerToken());
        if (! $device) {
            return $this->unauthorized('Вход на этом устройстве больше не действует — войдите заново');
        }
        $profile = EmployeeProfile::for($device->user);
        if ($profile->exists && $profile->login_blocked) {
            MobileDevices::revokeUser($device->user);

            return $this->unauthorized('Вход в почту закрыт администратором');
        }

        // Администратор требует 2FA, а сотрудник её ещё не включил: веб-почта в этом случае пускает
        // только в настройки безопасности; приложение просит сделать это в веб-почте (выйти можно).
        if ($profile->exists && $profile->require_2fa && empty(\App\Models\Webmail\Setting::for($device->user, true)['totp_enabled'])
            && ! ($request->isMethod('DELETE') && $request->is('api/v1/session'))) {
            return response()->json(['message' => 'Администратор требует двухфакторную защиту: включите её в веб-почте (Настройки → Безопасность), потом войдите в приложение заново', 'code' => 'twofaRequired'], 403);
        }

        ImapSession::forApp($request, $device->user, (int) $device->id);
        $request->attributes->set('mobile_device', $device);
        MobileDevices::touch($device, (string) $request->ip(), $this->appVersion($request));

        try {
            return $next($request);
        } catch (\Webklex\PHPIMAP\Exceptions\ConnectionFailedException|\Webklex\PHPIMAP\Exceptions\ImapServerErrorException $e) {
            // Служебный вход не пустил — ящик удалили или вход закрыт на стороне Dovecot.
            $chain = '';
            for ($x = $e; $x; $x = $x->getPrevious()) {
                $chain .= $x->getMessage() . ' ';
            }
            if (str_contains($chain, 'AUTHENTICATIONFAILED') || str_contains($chain, 'Authentication failed')) {
                MobileDevices::revoke((int) $device->id);

                return $this->unauthorized('Ящик недоступен — войдите заново');
            }

            return response()->json(['message' => 'Почтовый сервер не отвечает — попробуйте через минуту', 'code' => 'busy'], 503);
        }
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['message' => $message, 'code' => 'unauthorized'], 401);
    }

    /** «MailadminApp/1.2.0 (Android 15; Pixel 9)» → «1.2.0». */
    private function appVersion(Request $request): ?string
    {
        return preg_match('#MailadminApp/([0-9][0-9A-Za-z.\-]{0,18})#', (string) $request->userAgent(), $m) ? $m[1] : null;
    }
}
