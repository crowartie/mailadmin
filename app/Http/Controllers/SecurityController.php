<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Models\AdminLogin;
use App\Models\AppPassword;
use App\Models\AppSetting;
use App\Models\EmployeeProfile;
use App\Models\MailLogin;
use App\Models\User;
use App\Models\Vmail\Mailbox;
use App\Models\Webmail\Setting;
use App\Services\Server\Fail2ban;
use App\Services\Server\MailLog;
use App\Services\Server\Sessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Безопасность: блокировки fail2ban, входы, 2FA сотрудников, пароли приложений, сеансы, политики. */
class SecurityController extends Controller
{
    public function __construct(private readonly Fail2ban $f2b, private readonly Sessions $sessions, private readonly MailLog $log)
    {
    }

    public function index(Request $request, string $tab = 'overview'): Response
    {
        abort_unless(in_array($tab, ['overview', 'bans', 'logins', 'twofa', 'apppasswords', 'sessions'], true), 404);
        $bans = $this->f2b->banned();
        $employees = Mailbox::query()->where('active', 1)->orderBy('username')->get(['username', 'name']);
        $settings = Setting::query()->whereIn('user', $employees->pluck('username'))->get()->keyBy('user');
        $profiles = EmployeeProfile::query()->whereIn('username', $employees->pluck('username'))->get()->keyBy('username');
        $with2fa = $employees->filter(fn ($m) => (bool) (($settings[$m->username]->data ?? [])['totp_enabled'] ?? false))->count();
        $admins = User::query()->where('is_active', 1)->get();
        $stats = $this->log->readable() ? $this->log->stats() : null;

        $data = [
            'tab' => $tab,
            'available' => $bans !== null,
            'summary' => [
                'banned' => $bans === null ? null : count($bans),
                'attempts' => $stats['authFail'] ?? 0,
                'admins2fa' => $admins->filter(fn (User $u) => $u->hasTwoFactor())->count(),
                'admins' => $admins->count(),
                'employeesNo2fa' => $employees->count() - $with2fa,
                'employees' => $employees->count(),
                'weak' => $this->weakPasswordsCount(),
            ],
            'bans' => array_map(fn ($b) => $b + ['where' => Fail2ban::where($b['ip'])], $bans ?? []),
            'jails' => $tab === 'bans' ? $this->f2b->jails() : [],
            'sessions' => $this->sessions->all(null, $request->session()->getId()),
            'policies' => AppSetting::group('security'),
            'fail2ban' => AppSetting::group('fail2ban'),
            'failedTop' => $stats['failedLogins'] ?? [],
        ];

        if ($tab === 'logins') {
            $data['logins'] = $this->logins();
        }
        if ($tab === 'twofa') {
            $data['employees'] = $employees->map(fn ($m) => [
                'username' => $m->username, 'name' => $m->name ?: $m->username,
                'enabled' => (bool) (($settings[$m->username]->data ?? [])['totp_enabled'] ?? false),
                'required' => (bool) ($profiles[$m->username]->require_2fa ?? false),
            ])->values();
            $data['admins'] = $admins->map(fn (User $u) => ['email' => $u->email, 'name' => $u->name, 'enabled' => $u->hasTwoFactor(), 'role' => $u->role])->values();
        }
        if ($tab === 'apppasswords') {
            $data['appPasswords'] = AppPassword::query()->orderByDesc('id')->get()->map(fn (AppPassword $p) => [
                'id' => $p->id, 'user' => $p->username, 'name' => $p->name, 'active' => $p->active,
                'created' => $p->created_at?->toIso8601String(), 'lastUsed' => $p->last_used_at?->toIso8601String(),
            ]);
        }

        return Inertia::render('Security/Index', $data);
    }

    /** @return array<int,array<string,mixed>> */
    private function logins(): array
    {
        $admin = AdminLogin::query()->orderByDesc('id')->limit(150)->get()->map(fn (AdminLogin $l) => [
            'at' => $l->created_at->toIso8601String(), 'who' => $l->email, 'ip' => $l->ip, 'where' => 'админка',
            'result' => $l->result, 'label' => self::label($l->result), 'kind' => $l->result === 'ok' ? 'ok' : ($l->result === 'blocked' ? 'no' : 'warn'), 'agent' => $l->user_agent,
        ])->all();
        $mail = MailLogin::query()->orderByDesc('id')->limit(150)->get()->map(fn (MailLogin $l) => [
            'at' => $l->created_at->toIso8601String(), 'who' => $l->user, 'ip' => $l->ip, 'where' => 'веб-почта',
            'result' => $l->result, 'label' => self::label($l->result), 'kind' => $l->result === 'ok' || $l->result === 'new_device' ? 'ok' : ($l->result === 'blocked' ? 'no' : 'warn'), 'agent' => $l->agent,
        ])->all();
        $rows = array_merge($admin, $mail);
        // Входы по IMAP/POP3 и неудачные попытки — из журнала сервера.
        if ($this->log->readable()) {
            foreach ($this->log->events('auth', '', 150) as $e) {
                [$who, $where] = array_pad(explode(' → ', $e['who'], 2), 2, '');
                if (str_contains($e['who'], ' · ')) {
                    [$who, $ip] = explode(' · ', $e['who'], 2);
                    $rows[] = ['at' => $e['time'], 'who' => $who, 'ip' => $ip, 'where' => str_contains($e['what'], 'IMAP') ? 'IMAP' : (str_contains($e['what'], 'POP3') ? 'POP3' : 'Sieve'), 'result' => 'ok', 'label' => $e['what'], 'kind' => 'ok', 'agent' => ''];
                } else {
                    $rows[] = ['at' => $e['time'], 'who' => '—', 'ip' => $who, 'where' => strtoupper($where ?: 'smtp'), 'result' => 'bad_password', 'label' => $e['what'], 'kind' => 'warn', 'agent' => ''];
                }
            }
        }
        usort($rows, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($rows, 0, 300);
    }

    private static function label(string $r): string
    {
        return match ($r) {
            'ok' => 'вход', 'new_device' => 'вход с нового устройства', 'bad_password' => 'неверный пароль', 'bad_code' => 'неверный код', 'blocked' => 'заблокировано', 'impersonate' => 'вход администратора как сотрудник', default => $r,
        };
    }

    private function weakPasswordsCount(): int
    {
        // Признак утечки пароля мы проверяем при смене (HIBP); здесь — пароли, не менявшиеся дольше политики.
        $days = (int) (AppSetting::group('security')['password_days'] ?? 365);

        return $days > 0 ? Mailbox::query()->where('active', 1)->where('passwordlastchange', '<', now()->subDays($days))->count() : 0;
    }

    // ── Действия ────────────────────────────────────────────────────────

    public function unban(Request $request): RedirectResponse
    {
        $data = $request->validate(['ip' => ['required', 'ip']]);
        $this->f2b->unban($data['ip']);
        AdminAction::log('security.unban', $data['ip']);

        return back()->with('success', 'Адрес ' . $data['ip'] . ' разблокирован');
    }

    public function ban(Request $request): RedirectResponse
    {
        $data = $request->validate(['ip' => ['required', 'ip'], 'jail' => ['nullable', 'string', 'max:40']]);
        $this->f2b->ban($data['ip'], $data['jail'] ?: 'postfix');
        AdminAction::log('security.ban', $data['ip']);

        return back()->with('success', 'Адрес ' . $data['ip'] . ' заблокирован');
    }

    public function ignore(Request $request): RedirectResponse
    {
        $data = $request->validate(['ip' => ['required', 'ip']]);
        $this->f2b->ignore($data['ip']);
        AdminAction::log('security.whitelist', $data['ip']);

        return back()->with('success', 'Адрес ' . $data['ip'] . ' добавлен в белый список fail2ban');
    }

    public function kick(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['required', 'string', 'max:200']]);
        $this->sessions->kick($data['id']);
        AdminAction::log('security.kick', $data['id']);

        return back()->with('success', 'Сеанс завершён');
    }

    public function revokeAppPassword(AppPassword $password): RedirectResponse
    {
        $password->delete();
        AdminAction::log('security.app_password_revoke', $password->username, $password->name);

        return back()->with('success', 'Пароль приложения «' . $password->name . '» отозван');
    }

    /** Потребовать 2FA у сотрудника (или у всех). */
    public function require2fa(Request $request): RedirectResponse
    {
        $data = $request->validate(['user' => ['nullable', 'email'], 'all' => ['nullable', 'boolean'], 'off' => ['nullable', 'boolean']]);
        $users = ! empty($data['all']) ? Mailbox::query()->where('active', 1)->pluck('username')->all() : [$data['user'] ?? null];
        foreach (array_filter($users) as $u) {
            $p = EmployeeProfile::for($u);
            $p->require_2fa = empty($data['off']);
            $p->save();
        }
        AdminAction::log('security.require_2fa', ! empty($data['all']) ? 'все' : ($data['user'] ?? ''), empty($data['off']) ? 'включить при следующем входе' : 'требование снято');

        return back()->with('success', empty($data['off']) ? 'Сотрудник настроит защиту при следующем входе' : 'Требование снято');
    }

    public function reset2fa(Request $request): RedirectResponse
    {
        $data = $request->validate(['user' => ['required', 'email']]);
        Setting::patch($data['user'], ['totp_secret' => null, 'totp_enabled' => false]);
        AdminAction::log('security.reset_2fa', $data['user']);

        return back()->with('success', 'Двухфакторная защита у ' . $data['user'] . ' сброшена');
    }

    public function policies(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'min_password' => ['required', 'integer', 'min:6', 'max:64'], 'password_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'admin_2fa' => ['boolean'], 'all_2fa_internet' => ['boolean'], 'notify_new_device' => ['boolean'], 'app_passwords' => ['boolean'], 'telegram_security' => ['boolean'],
        ]);
        AppSetting::put('security', $data);
        AdminAction::log('settings.update', 'политики безопасности');

        return back()->with('success', 'Политики сохранены');
    }
}
