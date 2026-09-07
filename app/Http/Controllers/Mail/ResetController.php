<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\MailLogin;
use App\Models\Vmail\Mailbox;
use App\Services\Mail\ImapSession;
use App\Services\Vmail\MailboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/** Смена пароля по ссылке, которую администратор отправил на личную почту сотрудника. */
class ResetController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $user = Cache::get('pwreset.' . $token);
        if (! $user) {
            return redirect('/mail/login')->with('error', 'Ссылка устарела или уже использована — попросите администратора прислать новую');
        }

        return Inertia::render('Mail/Reset', ['token' => $token, 'user' => $user, 'minLength' => (int) (AppSetting::group('security')['min_password'] ?? 10), 'domain' => config('areas.default_domain')]);
    }

    public function store(Request $request, string $token, MailboxService $service): RedirectResponse
    {
        $user = Cache::get('pwreset.' . $token);
        if (! $user) {
            return redirect('/mail/login')->with('error', 'Ссылка устарела');
        }
        $min = (int) (AppSetting::group('security')['min_password'] ?? 10);
        $data = $request->validate(['password' => ['required', 'string', 'min:' . $min, 'max:200', 'confirmed']], ['password.min' => "Пароль короче {$min} символов", 'password.confirmed' => 'Пароли не совпадают']);
        $mailbox = Mailbox::query()->findOrFail($user);
        $mailbox->password = $service->hashPassword($data['password']);
        $mailbox->passwordlastchange = now();
        $mailbox->save();
        Cache::forget('pwreset.' . $token);

        try {
            ImapSession::login($request, $user, $data['password']);
            MailLogin::record($request, $user, 'ok');
        } catch (\Throwable) {
            return redirect('/mail/login')->with('success', 'Пароль изменён — войдите с новым паролем');
        }

        return redirect('/mail')->with('success', 'Пароль изменён. Не забудьте обновить его в почтовой программе и на телефоне.');
    }
}
