<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Services\Mail\ImapSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->session()->has('mail.user')) {
            return redirect('/mail');
        }

        return Inertia::render('Mail/Login', [
            'domain' => config('areas.default_domain', 'innotec.su'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $login = strtolower(trim($data['login']));
        if (! str_contains($login, '@')) {
            $login .= '@' . config('areas.default_domain', 'innotec.su');
        }

        try {
            ImapSession::login($request, $login, $data['password']);
        } catch (\Throwable $e) {
            return back()->withErrors(['login' => 'Не удалось войти: неверный адрес или пароль.'])->onlyInput('login');
        }

        return redirect()->intended('/mail');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(['mail.user', 'mail.secret']);
        $request->session()->regenerate();

        return redirect('/mail/login');
    }
}
