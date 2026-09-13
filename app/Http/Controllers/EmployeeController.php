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
        $wasBlocked = (bool) $profile->login_blocked;
        $profile->fill(['title' => $data['title'] ?? null, 'personal_email' => $data['personal_email'] ?? null, 'require_2fa' => (bool) ($data['require_2fa'] ?? false), 'login_blocked' => (bool) ($data['login_blocked'] ?? false), 'is_service' => (bool) ($data['is_service'] ?? false)]);
        $profile->save();
        // Запрет входа закрывает и клиентов (IMAP/SMTP/телефоны), а не только веб-почту; доставка остаётся.
        if ($wasBlocked !== $profile->login_blocked) {
            app(\App\Services\Vmail\MailboxService::class)->setLoginBlocked($model, $profile->login_blocked);
            \App\Models\AdminAction::log('mailbox.update', $profile->login_blocked ? 'вход закрыт' : 'вход открыт', $model->username);
        }
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

    /** Папки ящика и кому они открыты (по IMAP от имени сотрудника через master-пользователя). */
    public function shares(string $mailbox): \Illuminate\Http\JsonResponse
    {
        $model = Mailbox::query()->findOrFail($mailbox);
        try {
            $store = new \App\Services\Mail\MailStore(ImapSession::master($model->username));
            $folders = array_values(array_filter($store->folders(), fn ($f) => $f['role'] !== 'shared'));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'IMAP не отвечает: ' . mb_substr($e->getMessage(), 0, 160)], 500);
        }
        $svc = new \App\Services\Mail\FolderShares();
        $out = [];
        foreach ($folders as $f) {
            $out[] = ['path' => $f['path'], 'name' => $f['name'], 'depth' => $f['depth'], 'shares' => $svc->list($model->username, \App\Services\Mail\FolderShares::utf8($f['path']))];
        }

        return response()->json(['folders' => $out, 'candidates' => \App\Services\Mail\FolderShares::candidates($model->username)]);
    }

    public function share(Request $request, string $mailbox): \Illuminate\Http\JsonResponse
    {
        $model = Mailbox::query()->findOrFail($mailbox);
        $data = $request->validate(['folder' => ['required', 'string', 'max:200'], 'with' => ['required', 'email'], 'level' => ['required', 'in:reader,editor']]);
        $svc = new \App\Services\Mail\FolderShares();
        try {
            $svc->set($model->username, \App\Services\Mail\FolderShares::utf8($data['folder']), $data['with'], $data['level']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Не удалось выдать доступ: ' . mb_substr($e->getMessage(), 0, 200)], 500);
        }
        AdminAction::log('mailbox.update', $model->username, 'папка «' . \App\Services\Mail\FolderShares::utf8($data['folder']) . '» открыта для ' . $data['with'] . ' (' . ($data['level'] === 'editor' ? 'редактор' : 'читатель') . ')');

        return $this->shares($mailbox);
    }

    public function unshare(Request $request, string $mailbox): \Illuminate\Http\JsonResponse
    {
        $model = Mailbox::query()->findOrFail($mailbox);
        $data = $request->validate(['folder' => ['required', 'string', 'max:200'], 'with' => ['required', 'email']]);
        try {
            (new \App\Services\Mail\FolderShares())->remove($model->username, \App\Services\Mail\FolderShares::utf8($data['folder']), $data['with']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Не удалось снять доступ: ' . mb_substr($e->getMessage(), 0, 200)], 500);
        }
        AdminAction::log('mailbox.update', $model->username, 'папка «' . \App\Services\Mail\FolderShares::utf8($data['folder']) . '» закрыта для ' . $data['with']);

        return $this->shares($mailbox);
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
