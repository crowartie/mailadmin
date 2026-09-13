<?php

namespace App\Http\Controllers;

use App\Http\Requests\MailboxRequest;
use App\Models\Vmail\Domain;
use App\Models\Vmail\Forwarding;
use App\Models\Vmail\Mailbox;
use App\Services\Vmail\MailboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MailboxController extends Controller
{
    public function __construct(private readonly MailboxService $service)
    {
    }

    public function index(Request $request): Response
    {
        return $this->renderList($request, null);
    }

    /**
     * Карточка открывается поверх списка выдвижной панелью — поэтому
     * редактирование рендерит ту же страницу списка с prop `editing`.
     */
    public function edit(Request $request, string $mailbox): Response
    {
        $model = Mailbox::query()->findOrFail($mailbox);

        return $this->renderList($request, $this->cardData($model));
    }

    public function create(): Response
    {
        return Inertia::render('Mailboxes/Create', [
            'domains' => $this->domains(),
            'defaults' => [
                'quota' => (int) (\App\Models\AppSetting::group('limits')['default_quota_mb'] ?? 1024),
                'services' => $this->defaultServices(),
            ],
            'units' => app(\App\Services\Units\UnitService::class)->flat(),
        ]);
    }

    public function store(MailboxRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $mailbox = $this->service->create($data);
        $this->applyProfile($mailbox, $data);
        $service = (bool) ($data['is_service'] ?? false);

        return redirect('/mailboxes')->with('success', ($service ? 'Служебный ящик ' : 'Сотрудник ') . $mailbox->username . ' создан');
    }

    public function update(MailboxRequest $request, string $mailbox): RedirectResponse
    {
        $model = Mailbox::query()->findOrFail($mailbox);
        $this->service->update($model, $request->validated());
        $this->applyProfile($model, $request->validated());

        return redirect('/mailboxes')->with('success', "Изменения для {$model->username} сохранены");
    }

    public function destroy(string $mailbox): RedirectResponse
    {
        $model = Mailbox::query()->findOrFail($mailbox);
        $this->service->delete($model, 'admin@local');

        return redirect('/mailboxes')->with('success', "Ящик {$model->username} удалён");
    }

    private function renderList(Request $request, ?array $editing): Response
    {
        $search = $request->string('search')->toString();
        $filter = $request->string('filter')->toString();

        $serviceList = \App\Models\EmployeeProfile::serviceUsernames();
        // «Заблокированные» — и выключенные ящики (active=0, почта не принимается), и те, кому закрыт вход (почта приходит).
        $blockedLogin = \App\Models\EmployeeProfile::query()->where('login_blocked', true)->pluck('username')->all();
        $mailboxes = Mailbox::query()
            ->with('usedQuota')
            ->search($search)
            ->when($filter === 'admins', fn ($q) => $q->where(fn ($w) => $w->where('isadmin', 1)->orWhere('isglobaladmin', 1)))
            ->when($filter === 'blocked', fn ($q) => $q->where(fn ($w) => $w->where('active', 0)->orWhere('enableimap', 0)->orWhereIn('username', $blockedLogin ?: ['-'])))
            ->orderBy('username')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Mailbox $mailbox) => [
                'username' => $mailbox->username,
                'name' => $mailbox->name,
                'department' => $mailbox->department,
                'quotaMb' => $mailbox->quota_mb,
                'usedBytes' => $mailbox->usedQuota?->bytes ?? 0,
                'messages' => $mailbox->usedQuota?->messages ?? 0,
                'active' => $mailbox->active,
                'loginBlocked' => in_array($mailbox->username, $blockedLogin, true) || ! $mailbox->enableimap,
                'service' => in_array($mailbox->username, $serviceList, true),
                'imap' => (bool) $mailbox->enableimap,
                'smtp' => (bool) $mailbox->enablesmtp,
                'sogo' => (bool) $mailbox->enablesogo,
            ]);

        return Inertia::render('Mailboxes/Index', [
            'mailboxes' => $mailboxes,
            'filters' => ['search' => $search, 'filter' => $filter ?: 'all'],
            'editing' => $editing,
            'domains' => $this->domains(),
            'serviceFlags' => $this->serviceFlagLabels(),
        ]);
    }

    /** @return array<string,mixed> */
    private function cardData(Mailbox $model): array
    {
        $forwardings = Forwarding::query()
            ->where('address', $model->username)
            ->where('is_forwarding', 1)
            ->pluck('forwarding')
            ->all();

        $keepCopy = Forwarding::query()
            ->where('address', $model->username)
            ->whereColumn('address', 'forwarding')
            ->exists();

        // Дополнительные адреса: строки, которые ведут В этот ящик.
        $aliases = Forwarding::query()
            ->where('forwarding', $model->username)
            ->where('is_alias', 1)
            ->pluck('address')
            ->all();

        $memberships = Forwarding::query()
            ->where('forwarding', $model->username)
            ->where(fn ($q) => $q->where('is_list', 1)->orWhere('is_maillist', 1))
            ->pluck('address')
            ->all();

        return [
            'username' => $model->username,
            'local_part' => explode('@', $model->username)[0],
            'domain' => $model->domain,
            'name' => $model->name,
            'quota' => $model->quota,
            'active' => $model->active,
            'first_name' => $model->first_name,
            'last_name' => $model->last_name,
            'telephone' => $model->telephone,
            'mobile' => $model->mobile,
            'department' => $model->department,
            'rank' => $model->rank,
            'employeeid' => $model->employeeid,
            'recovery_email' => $model->recovery_email,
            'maildir' => $model->maildir_path,
            'created' => $model->created?->format('d.m.Y H:i'),
            'passwordChanged' => $model->passwordlastchange?->format('d.m.Y'),
            'forwardings' => $forwardings,
            'keep_copy' => $keepCopy,
            'aliases' => $aliases,
            'memberships' => $memberships,
            'isadmin' => (bool) $model->isadmin,
            'isglobaladmin' => (bool) $model->isglobaladmin,
            'services' => $this->currentServices($model),
        ] + $this->extras($model);
    }

    /** Подразделение и флаг «служебный» из формы создания/правки — в наш профиль. */
    private function applyProfile(Mailbox $mailbox, array $data): void
    {
        if (! array_key_exists('unit_id', $data) && ! array_key_exists('is_service', $data) && ! array_key_exists('middle_name', $data)) {
            return;
        }
        $profile = \App\Models\EmployeeProfile::for($mailbox->username);
        if (array_key_exists('middle_name', $data) && (string) $data['middle_name'] !== (string) $profile->middle_name) {
            $profile->middle_name = trim((string) $data['middle_name']) ?: null;
            $profile->save();
            if (! $profile->is_service) {
                app(\App\Services\Dav\EmployeeBook::class)->put($mailbox);
            }
        }
        if (array_key_exists('is_service', $data)) {
            $profile->is_service = (bool) $data['is_service'];
            $profile->save();
            $book = app(\App\Services\Dav\EmployeeBook::class);
            $profile->is_service ? $book->remove($mailbox) : $book->put($mailbox);
        }
        if (array_key_exists('unit_id', $data) && (int) $data['unit_id'] !== (int) $profile->unit_id) {
            app(\App\Services\Units\UnitService::class)->move($mailbox->username, $data['unit_id'] ? (int) $data['unit_id'] : null);
        }
    }

    /** Вкладки «Доступ» и «Устройства»: то, чего нет в схеме iRedMail. @return array<string,mixed> */
    private function extras(Mailbox $model): array
    {
        $profile = \App\Models\EmployeeProfile::for($model->username);
        $settings = \App\Models\Webmail\Setting::for($model->username);
        $shares = [];
        $calendars = [];
        try {
            $store = app(\App\Services\Dav\DavStore::class);
            $shares = $store->shares($model->username, \App\Services\Dav\DavStore::PERSONAL);
            $calendars = array_values(array_filter($store->calendars($model->username), fn ($c) => $c['kind'] === 'shared'));
        } catch (\Throwable) {
        }

        return [
            'profile' => [
                'unit_id' => $profile->unit_id, 'is_service' => (bool) $profile->is_service, 'middle_name' => $profile->middle_name, 'title' => $profile->title ?: $model->rank, 'personal_email' => $profile->personal_email ?: $model->recovery_email,
                'require_2fa' => (bool) $profile->require_2fa, 'login_blocked' => (bool) $profile->login_blocked, 'totp' => (bool) ($settings['totp_enabled'] ?? false),
            ],
            'units' => app(\App\Services\Units\UnitService::class)->flat(),
            'appPasswords' => \App\Models\AppPassword::query()->where('username', $model->username)->orderByDesc('id')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'active' => (bool) $p->active, 'created' => $p->created_at?->toIso8601String(), 'lastUsed' => $p->last_used_at?->toIso8601String()])->values(),
            'sessions' => app(\App\Services\Server\Sessions::class)->all($model->username),
            'logins' => \App\Models\MailLogin::query()->where('user', $model->username)->orderByDesc('id')->limit(10)->get()->map(fn ($l) => ['ip' => $l->ip, 'device' => \App\Models\MailSession::device($l->agent), 'result' => $l->result, 'at' => $l->created_at?->toIso8601String()])->values(),
            'calendarShares' => $shares,
            'sharedCalendars' => array_map(fn ($c) => ['name' => $c['name'], 'owner' => $c['owner']['name'] ?? '', 'readonly' => $c['readonly']], $calendars),
        ];
    }

    /** @return array<int,array<string,string>> */
    private function domains(): array
    {
        return Domain::query()
            ->where('active', 1)
            ->orderBy('domain')
            ->pluck('domain')
            ->map(fn (string $domain) => ['value' => $domain, 'label' => $domain])
            ->all();
    }

    /**
     * Человеческие названия для флагов, которые показываем в карточке.
     * Остальные служебные флаги выставляет сервер, руками их не трогаем.
     *
     * @return array<int,array<string,string>>
     */
    private function serviceFlagLabels(): array
    {
        return [
            ['key' => 'enableimap', 'label' => 'IMAP'],
            ['key' => 'enablepop3', 'label' => 'POP3'],
            ['key' => 'enablesmtp', 'label' => 'Отправка почты (SMTP)'],
            ['key' => 'enablemanagesieve', 'label' => 'Правила фильтрации (Sieve)'],
            ['key' => 'enablesogo', 'label' => 'Доступ к SOGo'],
            ['key' => 'enablesogowebmail', 'label' => 'Веб-почта'],
            ['key' => 'enablesogocalendar', 'label' => 'Календарь'],
            ['key' => 'enablesogoactivesync', 'label' => 'ActiveSync (телефоны)'],
        ];
    }

    /** @return array<string,bool> */
    private function currentServices(Mailbox $mailbox): array
    {
        $services = [];
        foreach (MailboxService::serviceFlags() as $flag) {
            $services[$flag] = (bool) $mailbox->{$flag};
        }
        foreach (MailboxService::sogoFlags() as $flag) {
            $services[$flag] = $mailbox->{$flag} === 'y';
        }

        return $services;
    }

    /** @return array<string,bool> */
    private function defaultServices(): array
    {
        $services = [];
        foreach (MailboxService::serviceFlags() as $flag) {
            $services[$flag] = true;
        }
        foreach (MailboxService::sogoFlags() as $flag) {
            $services[$flag] = true;
        }

        return $services;
    }
}
