<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\EmployeeProfile;
use App\Models\MailLogin;
use App\Models\MailSession;
use App\Models\Webmail\Setting;
use App\Services\Mail\ImapSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Вход в веб-почту: пароль проверяет IMAP, затем — код из приложения, если сотрудник включил защиту.
 * Пишем журнал входов, замечаем новые устройства, уважаем блокировку и требование 2FA от администратора.
 */
class LoginController extends Controller
{
    private const MAX_FAILURES = 8;

    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->session()->has('mail.user')) {
            return redirect('/mail');
        }

        return Inertia::render('Mail/Login', ['domain' => config('areas.default_domain', 'innotec.su')]);
    }

    public function store(Request $request, Google2FA $google2fa): RedirectResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);
        $login = strtolower(trim($data['login']));
        if (! str_contains($login, '@')) {
            $login .= '@' . config('areas.default_domain', 'innotec.su');
        }

        if (MailLogin::recentFailures($request->ip()) >= self::MAX_FAILURES) {
            MailLogin::record($request, $login, 'blocked');

            return back()->withErrors(['login' => 'Слишком много попыток. Подождите 15 минут.'])->onlyInput('login');
        }

        $profile = EmployeeProfile::for($login);
        if ($profile->exists && $profile->login_blocked) {
            MailLogin::record($request, $login, 'blocked');

            return back()->withErrors(['login' => 'Вход в веб-почту закрыт администратором. Почта продолжает приниматься.'])->onlyInput('login');
        }

        try {
            ImapSession::verify($login, $data['password']);
        } catch (\Throwable) {
            MailLogin::record($request, $login, 'bad_password');

            return back()->withErrors(['login' => 'Не удалось войти: неверный адрес или пароль.'])->onlyInput('login');
        }

        $settings = Setting::for($login, true);
        if (! empty($settings['totp_enabled']) && ! empty($settings['totp_secret'])) {
            // Пароль верен — ждём код. Сессия почты ещё не открыта.
            $request->session()->put('mail.pending', ['user' => $login, 'secret' => Crypt::encryptString($data['password'])]);

            return redirect('/mail/login/code');
        }

        return $this->complete($request, $login, $data['password'], $profile);
    }

    public function code(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('mail.pending')) {
            return redirect('/mail/login');
        }

        return Inertia::render('Mail/Challenge');
    }

    public function verifyCode(Request $request, Google2FA $google2fa): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        $pending = $request->session()->get('mail.pending');
        if (! $pending) {
            return redirect('/mail/login');
        }
        $settings = Setting::for($pending['user'], true);
        $secret = ! empty($settings['totp_secret']) ? Crypt::decryptString($settings['totp_secret']) : null;
        if (! $secret || ! $google2fa->verifyKey($secret, $request->input('code'), 1)) {
            MailLogin::record($request, $pending['user'], 'bad_code');

            return back()->withErrors(['code' => 'Код не подошёл. Проверьте время на телефоне и попробуйте ещё раз.']);
        }
        $request->session()->forget('mail.pending');

        return $this->complete($request, $pending['user'], Crypt::decryptString($pending['secret']), EmployeeProfile::for($pending['user']));
    }

    /** Открыть сессию почты, записать вход, заметить новое устройство, потребовать 2FA, если велел админ. */
    private function complete(Request $request, string $login, string $password, EmployeeProfile $profile): RedirectResponse
    {
        $device = MailSession::device($request->userAgent());
        $known = MailLogin::query()->where('user', $login)->where('result', 'ok')->where('created_at', '>=', now()->subDays(90))
            ->where('agent', 'like', '%' . substr((string) $request->userAgent(), 0, 40) . '%')->exists();
        $everLogged = MailLogin::query()->where('user', $login)->whereIn('result', ['ok', 'new_device'])->exists();

        ImapSession::login($request, $login, $password);
        MailSession::seen($request, $login);
        MailLogin::record($request, $login, $known || ! $everLogged ? 'ok' : 'new_device');

        if (! $known && $everLogged && (AppSetting::group('security')['notify_new_device'] ?? true)) {
            $this->notifyNewDevice($login, $device, $request->ip());
        }
        if ($profile->exists && $profile->require_2fa) {
            $request->session()->put('mail.force2fa', true);

            return redirect('/mail/settings/security');
        }

        return redirect()->intended('/mail');
    }

    private function notifyNewDevice(string $user, string $device, string $ip): void
    {
        try {
            $email = (new Email())
                ->from(new Address('noreply@' . config('areas.default_domain'), 'Почта ' . config('areas.default_domain')))
                ->to(new Address($user))
                ->subject('Вход в почту с нового устройства')
                ->text("В вашу почту {$user} только что вошли с нового устройства: {$device}, адрес {$ip}, " . now()->format('d.m.Y H:i') . ".\n\nЕсли это были не вы — смените пароль в веб-почте (Настройки → Безопасность) и завершите чужие сеансы там же.");
            (new Mailer(ImapSession::smtpLocal()))->send($email);
        } catch (\Throwable $e) {
            Log::warning('Уведомление о новом устройстве не отправлено', ['user' => $user, 'error' => $e->getMessage()]);
        }
    }

    public function destroy(Request $request): RedirectResponse
    {
        MailSession::query()->where('id', $request->session()->getId())->delete();
        $request->session()->forget(['mail.user', 'mail.secret', 'mail.master', 'mail.force2fa', 'mail.pending']);
        $request->session()->regenerate();

        return redirect('/mail/login');
    }
}
