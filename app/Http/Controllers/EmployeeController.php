<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Models\AppPassword;
use App\Models\EmployeeProfile;
use App\Models\MailSession;
use App\Models\Unit;
use App\Models\Vmail\Mailbox;
use App\Models\Webmail\Setting;
use App\Services\Mail\ImapSession;
use App\Services\Server\Sessions;
use App\Services\Units\UnitService;
use App\Support\Area;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** Карточка сотрудника, вкладки «Доступ» и «Устройства»: подразделение, 2FA, сеансы, вход от имени. Пароль задаёт только админ (вкладка «Общие»). */
class EmployeeController extends Controller
{
    public function __construct(private readonly UnitService $units, private readonly Sessions $sessions)
    {
    }

    public function access(Request $request, string $mailbox): RedirectResponse
    {
        $model = Mailbox::query()->findOrFail($mailbox);
        $data = $request->validate([
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'title' => ['nullable', 'string', 'max:120'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'require_2fa' => ['boolean'],
            'login_blocked' => ['boolean'],
            'is_service' => ['boolean'],
        ]);
        $profile = EmployeeProfile::for($model->username);
        $oldUnit = $profile->unit_id;
        $wasService = (bool) $profile->is_service;
        $profile->fill(['title' => $data['title'] ?? null, 'personal_email' => $data['personal_email'] ?? null, 'require_2fa' => (bool) ($data['require_2fa'] ?? false), 'login_blocked' => (bool) ($data['login_blocked'] ?? false), 'is_service' => (bool) ($data['is_service'] ?? false)]);
        $profile->save();
        if ($wasService !== $profile->is_service) {
            $book = app(\App\Services\Dav\EmployeeBook::class);
            $profile->is_service ? $book->remove($model) : $book->put($model);
        }
        if (($data['unit_id'] ?? null) !== $oldUnit) {
            $this->units->move($model->username, $data['unit_id'] ?? null);
        }
        if (filled($data['title'] ?? null) && $model->rank !== $data['title']) {
            $model->rank = $data['title'];
            $model->save();
        }
        AdminAction::log('mailbox.update', $model->username, 'доступ и подразделение');

        return back()->with('success', 'Доступ сохранён');
    }

    /** Войти в веб-почту сотрудника без его пароля (master-пользователь Dovecot). */
    public function impersonate(Request $request, string $mailbox): Response
    {
        $model = Mailbox::query()->findOrFail($mailbox);
        try {
            ImapSession::loginAs($request, $model->username);
        } catch (\Throwable $e) {
            return back()->with('error', 'Не удалось войти: ' . mb_substr($e->getMessage(), 0, 200));
        }
        MailSession::seen($request, $model->username, true);
        AdminAction::log('employee.impersonate', $model->username);

        return Inertia::location(Area::mailUrl($request) . '/mail');
    }

    /** Завершить сеанс (веб или IMAP) или все сразу. */
    public function kick(Request $request, string $mailbox): RedirectResponse
    {
        $model = Mailbox::query()->findOrFail($mailbox);
        $data = $request->validate(['id' => ['nullable', 'string', 'max:120'], 'all' => ['boolean']]);
        if ($data['all'] ?? false) {
            $this->sessions->kickAll($model->username);
            AppPassword::query()->where('username', $model->username)->update(['active' => false]);
            AdminAction::log('employee.devices', $model->username, 'все устройства отключены');

            return back()->with('success', 'Все сеансы завершены, пароли приложений отозваны — с телефонов и программ придётся войти заново');
        }
        if (filled($data['id'] ?? null)) {
            $this->sessions->kick($data['id']);
            AdminAction::log('security.kick', $model->username, $data['id']);
        }

        return back()->with('success', 'Сеанс завершён');
    }

    public function revokeAppPassword(string $mailbox, AppPassword $password): RedirectResponse
    {
        abort_unless($password->username === strtolower($mailbox), 404);
        $password->update(['active' => false]);
        AdminAction::log('employee.devices', $mailbox, 'отозван пароль «' . $password->name . '»');

        return back()->with('success', 'Пароль приложения «' . $password->name . '» отозван');
    }

    /** Сбросить 2FA сотрудника (потерял телефон). */
    public function reset2fa(string $mailbox): RedirectResponse
    {
        $model = Mailbox::query()->findOrFail($mailbox);
        Setting::patch($model->username, ['totp_secret' => null, 'totp_enabled' => false]);
        AdminAction::log('employee.devices', $model->username, 'сброшена 2FA');

        return back()->with('success', 'Двухфакторная защита сброшена — сотрудник подключит её заново при входе');
    }
}
