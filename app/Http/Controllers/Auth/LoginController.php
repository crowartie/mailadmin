<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminLogin;
use App\Models\User;
use App\Services\Mail\ImapSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    private const MAX_FAILURES = 8;

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (AdminLogin::recentFailures($request->ip()) >= self::MAX_FAILURES) {
            AdminLogin::record($request, $data['email'], 'blocked');

            return back()->withErrors(['email' => 'Слишком много попыток. Подождите 15 минут.']);
        }

        $user = User::where('email', strtolower($data['email']))->first();

        if (! $user || ! $user->is_active || ! $this->passwordOk($user, $data['password'])) {
            AdminLogin::record($request, $data['email'], 'bad_password');

            return back()->withErrors(['email' => 'Неверный адрес или пароль.'])->onlyInput('email');
        }

        Auth::login($user, remember: false);
        $request->session()->regenerate();

        if ($user->hasTwoFactor()) {
            // Пароль верен, но вход ещё не завершён — до кода сессия считается непроверенной.
            $request->session()->put('2fa.pending', true);

            return redirect('/login/code');
        }

        $request->session()->put('2fa.verified', true);
        AdminLogin::record($request, $user->email, 'ok');

        return redirect()->intended('/');
    }

    /** Администратор из сотрудников входит паролем своего ящика (проверка через IMAP). */
    private function passwordOk(User $user, string $password): bool
    {
        if ($user->imap_auth) {
            try {
                ImapSession::verify($user->email, $password);

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        return filled($user->password) && Hash::check($password, $user->password);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
