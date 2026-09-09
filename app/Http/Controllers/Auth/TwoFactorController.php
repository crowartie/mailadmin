<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminLogin;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function __construct(private readonly Google2FA $google2fa)
    {
    }

    /** Ввод кода после пароля. */
    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->get('2fa.pending')) {
            return redirect('/');
        }

        return Inertia::render('Auth/Challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();

        if (! $user || ! $request->session()->get('2fa.pending')) {
            return redirect('/login');
        }

        if (! $this->google2fa->verifyKey($user->totp_secret, $request->input('code'), 1)) {
            AdminLogin::record($request, $user->email, 'bad_code');

            return redirect('/login/code')->withErrors(['code' => 'Код не подошёл. Проверьте время на телефоне и попробуйте ещё раз.']);
        }

        $request->session()->forget('2fa.pending');
        $request->session()->put('2fa.verified', true);
        AdminLogin::record($request, $user->email, 'ok');

        return redirect()->intended('/');
    }

    /** Страница подключения приложения-аутентификатора. */
    public function setup(Request $request): Response
    {
        $user = $request->user();

        // Секрет живёт в сессии, пока пользователь не подтвердит его первым кодом.
        $secret = $request->session()->get('2fa.setup_secret') ?: $this->google2fa->generateSecretKey(32);
        $request->session()->put('2fa.setup_secret', $secret);

        $otpauth = $this->google2fa->getQRCodeUrl(config('app.name', 'Почта'), $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 0), new SvgImageBackEnd())))->writeString($otpauth);

        return Inertia::render('Auth/Setup', [
            'enabled' => $user->hasTwoFactor(),
            'secret' => $user->hasTwoFactor() ? null : chunk_split($secret, 4, ' '),
            'qr' => $user->hasTwoFactor() ? null : 'data:image/svg+xml;base64,' . base64_encode($svg),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        $secret = $request->session()->get('2fa.setup_secret');

        if (! $secret || ! $this->google2fa->verifyKey($secret, $request->input('code'), 1)) {
            return redirect('/security/2fa')->withErrors(['code' => 'Код не подошёл — приложение ещё не привязано. Отсканируйте QR ещё раз.']);
        }

        $user->forceFill(['totp_secret' => $secret, 'totp_enabled_at' => now()])->save();
        $request->session()->forget('2fa.setup_secret');
        $request->session()->put('2fa.verified', true);

        return redirect('/security')->with('success', 'Двухфакторная защита включена');
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $request->user()->forceFill(['totp_secret' => null, 'totp_enabled_at' => null])->save();

        return redirect('/security')->with('success', 'Двухфакторная защита выключена');
    }
}
