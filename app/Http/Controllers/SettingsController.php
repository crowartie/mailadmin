<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Models\AppSetting;
use App\Models\Backup;
use App\Models\User;
use App\Models\Vmail\Domain;
use App\Models\Vmail\Mailbox;
use App\Services\Server\Alerts;
use App\Services\Server\AmavisConfig;
use App\Services\Server\BackupService;
use App\Services\Server\Certificate;
use App\Services\Server\Ctl;
use App\Services\Server\DnsCheck;
use App\Services\Server\Quarantine;
use App\Services\Server\WbList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Настройки сервера: домены и DNS, антиспам и карантин, лимиты, сертификат, копии, администраторы, уведомления. */
class SettingsController extends Controller
{
    public const TABS = ['domains', 'spam', 'limits', 'cert', 'backup', 'admins', 'alerts', 'cloud'];

    public function __construct(
        private readonly AmavisConfig $amavis,
        private readonly WbList $wblist,
        private readonly Quarantine $quarantine,
        private readonly BackupService $backups,
        private readonly Certificate $cert,
        private readonly Alerts $alerts,
    ) {
    }

    public function index(string $tab = 'domains'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $data = ['tab' => $tab, 'ctl' => Ctl::available()];

        $data += match ($tab) {
            'domains' => $this->domainsData(),
            'spam' => $this->spamData(),
            'limits' => [
                'limits' => AppSetting::group('limits'),
                'sizeLimitMb' => $this->safe(fn () => $this->amavis->current()['sizeLimitMb'], 15),
                'fail2ban' => AppSetting::group('fail2ban'),
                'throttle' => $this->safe(fn () => \App\Services\Server\Throttle::get(), ['max_msgs' => 0, 'period_min' => 60, 'enabled' => false]),
            ],
            'cert' => [
                'cert' => $this->safe(fn () => $this->cert->info()),
                'lastAttempt' => $this->safe(fn () => $this->cert->lastAttempt()),
                'names' => $this->expectedNames(),
            ],
            'backup' => $this->backupData(),
            'admins' => [
                'admins' => User::query()->orderBy('name')->get()->map(fn (User $u) => [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role ?? 'admin', 'roleTitle' => $u->roleTitle(),
                    'active' => (bool) $u->is_active, 'twofa' => $u->hasTwoFactor(), 'imap' => (bool) $u->imap_auth, 'me' => $u->id === auth()->id(),
                ]),
                'roles' => User::ROLES,
                'employees' => Mailbox::query()->where('active', 1)->orderBy('username')->get(['username', 'name'])->map(fn ($m) => ['username' => $m->username, 'name' => $m->name ?: $m->username]),
            ],
            'alerts' => ['alerts' => AppSetting::group('alerts'), 'channels' => AppSetting::group('channels')],
            'cloud' => $this->cloudData(),
        };

        return Inertia::render('Settings/Index', $data);
    }

    // ── Домены и DNS ─────────────────────────────────────────────────────
    private function domainsData(): array
    {
        $mailHost = $this->mailHost();
        $domains = Domain::query()->withCount(['mailboxes', 'aliases'])->orderBy('domain')->get();

        return [
            'mailHost' => $mailHost,
            'reports' => $this->safe(fn () => (new \App\Services\Server\Reports())->summary(30)),
            'reportsMailbox' => (string) (AppSetting::group('reports')['mailbox'] ?: 'postmaster@' . config('areas.default_domain')),
            'mtasts' => AppSetting::group('mtasts') + ['host' => 'mta-sts.' . config('areas.default_domain'), 'inCert' => in_array('mta-sts.' . config('areas.default_domain'), $this->safe(fn () => $this->cert->info()['names'] ?? [], []), true)],
            'domains' => $domains->map(fn (Domain $d) => [
                'domain' => $d->domain, 'description' => $d->description, 'active' => $d->active,
                'mailboxes' => $d->mailboxes_count, 'aliases' => $d->aliases_count,
                'mailboxLimit' => $d->mailboxes, 'aliasLimit' => $d->aliases, 'maxQuotaMb' => $d->maxquota, 'quotaMb' => $d->quota,
                'dns' => Cache::remember('dns.check.' . $d->domain, 600, fn () => $this->safe(fn () => (new DnsCheck())->check($d->domain, $mailHost, $this->dkimTxt($d->domain)), [])),
                'dkim' => $this->dkim($d->domain),
            ]),
        ];
    }

    public function recheckDns(Request $request): RedirectResponse
    {
        foreach (Domain::query()->pluck('domain') as $d) {
            Cache::forget('dns.check.' . $d);
            Cache::forget('dns.ptr.' . $d);
        }

        return back()->with('success', 'DNS перепроверен');
    }

    public function saveDomain(Request $request, string $domain): RedirectResponse
    {
        $d = Domain::query()->findOrFail($domain);
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'mailboxLimit' => ['required', 'integer', 'min:-1', 'max:100000'],
            'aliasLimit' => ['required', 'integer', 'min:-1', 'max:100000'],
            'maxQuotaMb' => ['required', 'integer', 'min:0', 'max:10000000'],
            'active' => ['boolean'],
        ]);
        $d->description = $data['description'] ?? '';
        $d->mailboxes = $data['mailboxLimit'];
        $d->aliases = $data['aliasLimit'];
        $d->maxquota = $data['maxQuotaMb'];
        $d->active = $data['active'] ?? true;
        $d->modified = now();
        $d->save();
        AdminAction::log('settings.update', 'домен ' . $domain);

        return back()->with('success', 'Домен ' . $domain . ' сохранён');
    }

    /** MTA-STS: имя в сертификат + server_name, политика отдаётся по /.well-known/mta-sts.txt. */
    public function enableMtaSts(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['testing', 'enforce'])]]);
        $host = 'mta-sts.' . config('areas.default_domain');
        try {
            $out = Ctl::out('cert-expand', [$host], 300);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Сертификат не расширен (нужна A-запись ' . $host . ' на этот сервер и открытый порт 80): ' . mb_substr($e->getMessage(), 0, 300));
        }
        if (! str_contains($out, 'rc=0') || str_contains($out, 'Some challenges have failed')) {
            return back()->with('error', 'Let\'s Encrypt не выдал имя ' . $host . ': ' . mb_substr($out, 0, 300));
        }
        Cache::forget('cert.info');
        AppSetting::put('mtasts', ['enabled' => true, 'mode' => $data['mode'], 'id' => now()->format('YmdHi')]);
        AdminAction::log('settings.update', 'MTA-STS', $data['mode']);

        return back()->with('success', 'MTA-STS включён: добавьте TXT-запись _mta-sts (значение показано ниже)');
    }

    public function mtaStsMode(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['testing', 'enforce', 'off'])]]);
        AppSetting::put('mtasts', $data['mode'] === 'off' ? ['enabled' => false] : ['enabled' => true, 'mode' => $data['mode'], 'id' => now()->format('YmdHi')]);
        AdminAction::log('settings.update', 'MTA-STS', $data['mode']);

        return back()->with('success', $data['mode'] === 'off' ? 'MTA-STS выключен (обновите TXT _mta-sts новым id или удалите)' : 'Режим MTA-STS: ' . $data['mode'] . ' — обновите id в TXT _mta-sts');
    }

    public function fetchReports(): RedirectResponse
    {
        \Illuminate\Support\Facades\Artisan::call('reports:fetch');

        return back()->with('success', trim(\Illuminate\Support\Facades\Artisan::output()));
    }

    public function rotateDkim(Request $request): RedirectResponse
    {
        $domain = $request->validate(['domain' => ['required', 'string', 'regex:/^[a-z0-9.-]+$/']])['domain'];
        try {
            Ctl::out('dkim-rotate', [$domain], 60);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Ключ не сменён: ' . $e->getMessage());
        }
        Cache::forget('dkim.' . $domain);
        Cache::forget('dns.check.' . $domain);
        AdminAction::log('dkim.rotate', $domain);

        return back()->with('success', 'Новый ключ DKIM создан — обновите TXT-запись в DNS, старая подпись перестала действовать');
    }

    private function dkimTxt(string $domain): ?string
    {
        [$code, $out] = Ctl::run('dkim-txt', [$domain], 10);

        return $code === 0 ? trim($out) : null;
    }

    private function dkim(string $domain): ?array
    {
        return Cache::remember('dkim.' . $domain, 600, function () use ($domain) {
            $txt = $this->dkimTxt($domain);
            if (! $txt) {
                return null;
            }
            [$code, $info] = Ctl::run('dkim-info', [$domain], 10);
            $bits = preg_match('/bits=(\d+)/', $info, $m) ? (int) $m[1] : null;
            $mtime = preg_match('/mtime=(\d+)/', $info, $m) ? (int) $m[1] : null;

            return ['selector' => 'dkim', 'host' => 'dkim._domainkey.' . $domain, 'txt' => $txt, 'bits' => $bits, 'since' => $mtime ? date('Y-m-d', $mtime) : null];
        });
    }

    // ── Антиспам и карантин ──────────────────────────────────────────────
    private function spamData(): array
    {
        return [
            'spam' => $this->safe(fn () => $this->amavis->current(), ['tag' => 2, 'tag2' => 6.2, 'kill' => 6.9, 'cutoff' => 10, 'virus' => false, 'greylist' => false, 'sizeLimitMb' => 15]),
            'wblist' => $this->safe(fn () => $this->wblist->all(), []),
            'quarantine' => $this->safe(fn () => $this->quarantine->list(100), []),
            'quarantinePolicy' => AppSetting::group('quarantine'),
            'senders' => AppSetting::group('senders'),
            'senderRules' => \App\Models\SenderRule::query()->orderBy('kind')->orderBy('value')->get()->map(fn ($r) => [
                'id' => $r->id, 'kind' => $r->kind, 'match' => $r->match, 'value' => $r->value, 'source' => $r->source, 'votes' => $r->votes, 'by' => $r->created_by, 'at' => $r->created_at?->format('d.m.Y'),
            ]),
            'senderPending' => $this->safe(fn () => \App\Services\Mail\SenderRules::pending(), []),
        ];
    }

    public function saveSenders(Request $request): RedirectResponse
    {
        $data = $request->validate(['ham_global' => ['boolean'], 'spam_votes' => ['required', 'integer', 'min:1', 'max:50'], 'lists_votes' => ['required', 'integer', 'min:1', 'max:50']]);
        $data['ham_global'] = (bool) ($data['ham_global'] ?? false);
        AppSetting::put('senders', $data);
        AdminAction::log('settings.update', 'решения сотрудников');

        return back()->with('success', 'Сохранено');
    }

    /** Сделать личную отметку общим правилом — не дожидаясь голосов. */
    public function promoteSender(Request $request, \App\Services\Mail\SenderRules $senders): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:ham,spam,lists'], 'match' => ['required', 'in:address,domain'], 'value' => ['required', 'string', 'max:255']]);
        try {
            $senders->approve($data['kind'], $data['match'], $data['value'], auth()->user()?->email);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        AdminAction::log('settings.update', 'заявка по отправителю утверждена', $data['kind'] . ' ' . $data['value']);

        return back()->with('success', ($data['kind'] === 'ham' ? 'Добавлено в общий белый список: ' : 'Правило стало общим: ') . $data['value']);
    }

    public function dismissSender(Request $request): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:ham,spam,lists'], 'value' => ['required', 'string', 'max:255']]);
        \App\Services\Mail\SenderRules::dismiss($data['kind'], $data['value']);
        AdminAction::log('settings.update', 'заявка по отправителю отклонена', $data['kind'] . ' ' . $data['value']);

        return back()->with('success', 'Заявка отклонена: ' . $data['value']);
    }

    public function demoteSender(\App\Models\SenderRule $rule, \App\Services\Mail\SenderRules $senders): RedirectResponse
    {
        try {
            $senders->demote($rule);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        AdminAction::log('settings.update', 'общее правило снято', $rule->value);

        return back()->with('success', 'Общее правило снято: ' . $rule->value);
    }

    public function saveSpam(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tag2' => ['required', 'numeric', 'min:1', 'max:20'], 'kill' => ['required', 'numeric', 'min:1', 'max:30'], 'cutoff' => ['required', 'numeric', 'min:1', 'max:50'],
            'virus' => ['boolean'], 'greylist' => ['boolean'],
        ]);
        if ($data['kill'] < $data['tag2']) {
            return back()->with('error', 'Порог «блокировать» не может быть ниже порога «помечать»');
        }
        try {
            $cur = $this->amavis->current();
            $this->amavis->setLevels(['tag2' => $data['tag2'], 'kill' => $data['kill'], 'cutoff' => $data['cutoff']]);
            if (($data['virus'] ?? false) !== $cur['virus']) {
                $this->amavis->setVirus((bool) ($data['virus'] ?? false));
            }
            if (($data['greylist'] ?? false) !== $cur['greylist']) {
                $this->amavis->setGreylist((bool) ($data['greylist'] ?? false));
            }
            $this->amavis->apply();
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Не применилось: ' . $e->getMessage());
        }
        AdminAction::log('settings.update', 'антиспам', 'помечать от ' . $data['tag2'] . ', блокировать от ' . $data['kill']);

        return back()->with('success', 'Настройки антиспама применены');
    }

    public function addWblist(Request $request): RedirectResponse
    {
        $data = $request->validate(['pattern' => ['required', 'string', 'max:120'], 'wb' => ['required', Rule::in(['W', 'B'])], 'note' => ['nullable', 'string', 'max:120']]);
        try {
            $this->wblist->add($data['pattern'], $data['wb'], $data['note'] ?? '');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        AdminAction::log('wblist.add', $data['pattern'], $data['wb'] === 'W' ? 'белый' : 'чёрный');

        return back()->with('success', ($data['wb'] === 'W' ? 'В белый список: ' : 'В чёрный список: ') . $data['pattern']);
    }

    public function removeWblist(int $id): RedirectResponse
    {
        $this->wblist->remove($id);
        AdminAction::log('wblist.delete', '#' . $id);

        return back()->with('success', 'Убрано из списка');
    }

    public function saveQuarantine(Request $request): RedirectResponse
    {
        $data = $request->validate(['digest' => ['boolean'], 'digest_time' => ['required', 'date_format:H:i'], 'keep_days' => ['required', 'integer', 'min:1', 'max:365']]);
        AppSetting::put('quarantine', $data + ['digest' => false]);
        AdminAction::log('settings.update', 'карантин');

        return back()->with('success', 'Правила карантина сохранены');
    }

    public function releaseQuarantine(Request $request, string $id): RedirectResponse
    {
        $secret = $request->validate(['secret' => ['required', 'string', 'max:32']])['secret'];
        try {
            $this->quarantine->release($id, $secret);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Не выпущено: ' . $e->getMessage());
        }
        AdminAction::log('quarantine.release', $id);

        return back()->with('success', 'Письмо доставлено получателю');
    }

    public function deleteQuarantine(string $id): RedirectResponse
    {
        $this->quarantine->delete($id);
        AdminAction::log('quarantine.delete', $id);

        return back()->with('success', 'Удалено из карантина');
    }

    // ── Вложения и лимиты ────────────────────────────────────────────────
    public function saveLimits(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sizeLimitMb' => ['required', 'integer', 'min:1', 'max:1024'],
            'default_quota_mb' => ['required', 'integer', 'min:0', 'max:1000000'],
            'blocked_ext' => ['nullable', 'string', 'max:300'],
            'max_recipients' => ['required', 'integer', 'min:1', 'max:5000'],
            'maxretry' => ['required', 'integer', 'min:2', 'max:100'], 'findtime' => ['required', 'integer', 'min:1', 'max:1440'], 'bantime_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'out_max_msgs' => ['required', 'integer', 'min:0', 'max:100000'], 'out_period_min' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);
        try {
            \App\Services\Server\Throttle::set((int) $data['out_max_msgs'], (int) $data['out_period_min']);
        } catch (\Throwable $e) {
            return back()->with('error', 'Лимит исходящих не записан в iRedAPD: ' . mb_substr($e->getMessage(), 0, 160));
        }
        try {
            if ($data['sizeLimitMb'] !== $this->amavis->current()['sizeLimitMb']) {
                $this->amavis->setSizeLimit($data['sizeLimitMb']);
            }
            Ctl::out('postconf-set', ['smtpd_recipient_limit', (string) $data['max_recipients']], 30);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Postfix не принял значение: ' . $e->getMessage());
        }
        AppSetting::put('limits', ['default_quota_mb' => $data['default_quota_mb'], 'blocked_ext' => $data['blocked_ext'] ?? '', 'max_recipients' => $data['max_recipients']]);
        AppSetting::put('fail2ban', ['maxretry' => $data['maxretry'], 'findtime' => $data['findtime'], 'bantime_hours' => $data['bantime_hours']]);
        // Jail «mailadmin» (админка и веб-почта) перечитывает пороги сразу; штатные jail'ы iRedMail не трогаем.
        try {
            Ctl::out('f2b-config', [(string) $data['maxretry'], (string) $data['findtime'], (string) $data['bantime_hours']], 60);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Лимиты сохранены, но fail2ban не перечитал правила: ' . mb_substr($e->getMessage(), 0, 200));
        }
        AdminAction::log('settings.update', 'лимиты', 'письмо до ' . $data['sizeLimitMb'] . ' МБ');

        return back()->with('success', 'Лимиты сохранены');
    }

    // ── Сертификат ───────────────────────────────────────────────────────
    public function renewCert(): RedirectResponse
    {
        $r = $this->cert->renew();
        AdminAction::log('cert.renew', $this->mailHost(), $r['ok'] ? 'успешно' : 'ошибка');

        return back()->with($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Сертификат проверен и продлён, службы перечитали его' : 'Продление не удалось')->with('certOutput', $r['output']);
    }

    // ── Резервные копии ──────────────────────────────────────────────────
    private function backupData(): array
    {
        return [
            'backup' => AppSetting::group('backup'),
            'dirCheck' => $this->backups->checkDir(),
            'running' => (bool) Cache::get('backup.running'),
            'history' => Backup::query()->orderByDesc('id')->limit(30)->get()->map(fn (Backup $b) => [
                'id' => $b->id, 'file' => $b->file, 'size' => $b->size, 'seconds' => $b->seconds, 'parts' => $b->parts, 'status' => $b->status, 'error' => $b->error, 'at' => $b->created_at?->toIso8601String(),
            ]),
            'files' => $this->safe(fn () => $this->backups->files(), []),
            'employees' => Mailbox::query()->where('active', 1)->orderBy('username')->pluck('username'),
        ];
    }

    public function saveBackup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dir' => ['required', 'string', 'regex:#^/[A-Za-z0-9/_.-]+$#'], 'time' => ['required', 'date_format:H:i'],
            'keep_daily' => ['required', 'integer', 'min:1', 'max:365'], 'keep_weekly' => ['required', 'integer', 'min:0', 'max:104'],
            'mail' => ['boolean'], 'db' => ['boolean'], 'config' => ['boolean'], 'vm_snapshot' => ['boolean'],
        ]);
        AppSetting::put('backup', $data + ['mail' => false, 'db' => false, 'config' => false, 'vm_snapshot' => false]);
        AdminAction::log('settings.update', 'резервные копии', $data['dir'] . ' в ' . $data['time']);

        return back()->with('success', 'Расписание копий сохранено');
    }

    public function runBackup(): RedirectResponse
    {
        if (Cache::get('backup.running')) {
            return back()->with('error', 'Копия уже выполняется');
        }
        $this->backups->runInBackground();
        AdminAction::log('backup.run');

        return back()->with('success', 'Копия запущена — итог появится в журнале ниже');
    }

    public function restoreBackup(Request $request): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'string', 'regex:#^/[A-Za-z0-9/_.-]+\.tar\.gz$#'], 'user' => ['required', 'email']]);
        try {
            $out = $this->backups->restoreMailbox($data['file'], strtolower($data['user']));
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Не восстановлено: ' . mb_substr($e->getMessage(), 0, 300));
        }
        AdminAction::log('backup.restore', $data['user'], basename($data['file']));

        return back()->with('success', 'Ящик ' . $data['user'] . ' восстановлен из ' . basename($data['file']) . ($out ? ' (' . mb_substr($out, 0, 120) . ')' : ''));
    }

    // ── Администраторы ───────────────────────────────────────────────────
    public function storeAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'imap' => ['boolean'],
            'password' => ['nullable', 'string', 'min:10', 'max:200'],
        ]);
        $imap = (bool) ($data['imap'] ?? false);
        if ($imap && ! Mailbox::query()->where('username', strtolower($data['email']))->exists()) {
            return back()->with('error', 'Для входа паролем ящика адрес должен быть почтовым ящиком на этом сервере');
        }
        if (! $imap && blank($data['password'] ?? null)) {
            return back()->with('error', 'Задайте пароль или включите вход паролем почтового ящика');
        }
        $u = User::create([
            'email' => strtolower($data['email']), 'name' => $data['name'], 'role' => $data['role'], 'is_active' => true,
            'imap_auth' => $imap, 'password' => $imap ? null : $data['password'],
        ]);
        AdminAction::log('admin.create', $u->email, $u->roleTitle());

        return back()->with('success', $u->name . ' — ' . $u->roleTitle());
    }

    public function updateAdmin(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'], 'role' => ['sometimes', Rule::in(array_keys(User::ROLES))],
            'active' => ['sometimes', 'boolean'], 'password' => ['nullable', 'string', 'min:10', 'max:200'], 'reset2fa' => ['sometimes', 'boolean'],
        ]);
        if ($user->id === auth()->id() && (($data['active'] ?? true) === false || (isset($data['role']) && $data['role'] !== $user->role))) {
            return back()->with('error', 'Себе нельзя менять роль и отключать доступ — попросите другого администратора');
        }
        if (isset($data['role']) && $user->role === 'owner' && $data['role'] !== 'owner' && User::query()->where('role', 'owner')->where('id', '!=', $user->id)->doesntExist()) {
            return back()->with('error', 'Должен остаться хотя бы один главный администратор');
        }
        $user->fill(array_intersect_key($data, array_flip(['name', 'role'])));
        if (array_key_exists('active', $data)) {
            $user->is_active = (bool) $data['active'];
        }
        if (filled($data['password'] ?? null)) {
            $user->password = $data['password'];
            $user->imap_auth = false;
        }
        if ($data['reset2fa'] ?? false) {
            $user->totp_secret = null;
            $user->totp_enabled_at = null;
        }
        $user->save();
        AdminAction::log('admin.update', $user->email, $user->roleTitle() . ($user->is_active ? '' : ', отключён'));

        return back()->with('success', 'Сохранено: ' . $user->name);
    }

    public function destroyAdmin(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Себя снять нельзя');
        }
        if ($user->role === 'owner' && User::query()->where('role', 'owner')->where('id', '!=', $user->id)->doesntExist()) {
            return back()->with('error', 'Должен остаться хотя бы один главный администратор');
        }
        $user->delete();
        AdminAction::log('admin.delete', $user->email);

        return back()->with('success', 'Доступ снят: ' . $user->email);
    }

    // ── Уведомления ──────────────────────────────────────────────────────
    public function saveAlerts(Request $request): RedirectResponse
    {
        $a = $request->validate([
            'queue' => ['boolean'], 'queue_size' => ['required', 'integer', 'min:1', 'max:100000'], 'queue_age_hours' => ['required', 'integer', 'min:1', 'max:240'],
            'disk' => ['boolean'], 'disk_pct' => ['required', 'integer', 'min:50', 'max:99'], 'services' => ['boolean'], 'backup' => ['boolean'], 'admin_login' => ['boolean'],
            'digest' => ['boolean'], 'digest_time' => ['required', 'date_format:H:i'],
            'emails' => ['nullable', 'string', 'max:500'], 'telegram_token' => ['nullable', 'string', 'max:100'], 'telegram_chat' => ['nullable', 'string', 'max:40'], 'telegram_proxy' => ['nullable', 'string', 'max:200'],
        ]);
        $channels = array_intersect_key($a, array_flip(['emails', 'telegram_token', 'telegram_chat', 'telegram_proxy']));
        AppSetting::put('alerts', array_diff_key($a, $channels) + ['queue' => false, 'disk' => false, 'services' => false, 'backup' => false, 'admin_login' => false, 'digest' => false]);
        AppSetting::put('channels', array_map(fn ($v) => trim((string) $v), $channels));
        AdminAction::log('settings.update', 'уведомления');

        return back()->with('success', 'Уведомления сохранены');
    }

    public function testAlerts(): RedirectResponse
    {
        $ch = AppSetting::group('channels');
        $text = '✅ Проверка уведомлений почтового сервера ' . config('areas.default_domain') . ' — ' . now()->format('d.m.Y H:i');
        $tg = $this->alerts->telegram($text);
        $this->alerts->send($text);
        $parts = [];
        if (filled($ch['emails'])) {
            $parts[] = 'письмо на ' . $ch['emails'];
        }
        $parts[] = filled($ch['telegram_token']) ? ($tg ? 'Telegram доставлен' : 'Telegram не ответил — проверьте токен, chat_id и прокси') : 'Telegram не настроен';

        return back()->with($tg || filled($ch['emails']) ? 'success' : 'error', 'Отправлено: ' . implode('; ', $parts));
    }

    // ── Файлы: Nextcloud для больших вложений ────────────────────────────
    private function cloudData(): array
    {
        $s = \App\Services\Cloud\Nextcloud::settings();
        unset($s['app_password']);
        $s['connected'] = filled($s['login']);

        return ['cloud' => $s, 'cloudStatus' => $s['connected'] ? $this->safe(fn () => (new \App\Services\Cloud\Nextcloud())->status()) : null, 'sizeLimitMb' => $this->safe(fn () => $this->amavis->current()['sizeLimitMb'], 15)];
    }

    /** Шаг 1: адрес облака → ссылка для входа (Login Flow v2). */
    public function cloudStart(Request $request): \Illuminate\Http\JsonResponse
    {
        $url = $request->validate(['url' => ['required', 'string', 'max:200']])['url'];
        try {
            $flow = \App\Services\Cloud\Nextcloud::loginFlowStart($url);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $request->session()->put('cloud.flow', $flow);

        return response()->json(['login' => $flow['login']]);
    }

    /** Шаг 2: опрос, пока администратор подтверждает доступ в Nextcloud. */
    public function cloudPoll(Request $request): \Illuminate\Http\JsonResponse
    {
        $flow = $request->session()->get('cloud.flow');
        if (! $flow) {
            return response()->json(['message' => 'Подключение не начато'], 422);
        }
        try {
            $r = \App\Services\Cloud\Nextcloud::loginFlowPoll($flow['endpoint'], $flow['token']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if (! $r) {
            return response()->json(['done' => false]);
        }
        \App\Services\Cloud\Nextcloud::saveCredentials($r['server'] ?: $flow['url'], $r['loginName'], $r['appPassword']);
        $request->session()->forget('cloud.flow');
        $nc = new \App\Services\Cloud\Nextcloud();
        $folderError = null;
        try {
            $nc->ensureFolder($nc->folder());
        } catch (\Throwable $e) {
            $folderError = $e->getMessage();
        }
        AdminAction::log('settings.update', 'облако', 'подключён ' . $r['loginName'] . ' @ ' . ($r['server'] ?: $flow['url']));

        return response()->json(['done' => true, 'user' => $r['loginName'], 'folderError' => $folderError]);
    }

    /** Ручное подключение: логин и пароль приложения, созданный в Nextcloud. */
    public function cloudManual(Request $request): RedirectResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:200'], 'login' => ['required', 'string', 'max:120'], 'app_password' => ['required', 'string', 'max:200']]);
        $url = rtrim(trim($data['url']), '/');
        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        \App\Services\Cloud\Nextcloud::saveCredentials($url, $data['login'], $data['app_password']);
        $nc = new \App\Services\Cloud\Nextcloud();
        $st = $nc->status();
        if (! $st['ok']) {
            \App\Services\Cloud\Nextcloud::disconnect();

            return back()->with('error', 'Не подключилось: ' . $st['message']);
        }
        try {
            $nc->ensureFolder($nc->folder());
        } catch (\Throwable $e) {
            return back()->with('error', 'Подключено, но папка не создана: ' . $e->getMessage());
        }
        AdminAction::log('settings.update', 'облако', 'подключён ' . $data['login']);

        return back()->with('success', 'Nextcloud подключён, папка «' . $nc->folder() . '» готова');
    }

    public function cloudSave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'], 'folder' => ['required', 'string', 'max:120', 'regex:#^[^/\\:*?"<>|]+(/[^/\\:*?"<>|]+)*$#u'],
            'threshold_mb' => ['required', 'integer', 'min:1', 'max:1024'], 'expire_days' => ['required', 'integer', 'min:0', 'max:3650'], 'link_password' => ['nullable', 'string', 'max:64'],
        ]);
        AppSetting::put('cloud', ['enabled' => (bool) ($data['enabled'] ?? false), 'folder' => trim($data['folder'], '/'), 'threshold_mb' => $data['threshold_mb'], 'expire_days' => $data['expire_days'], 'link_password' => (string) ($data['link_password'] ?? '')]);
        if (\App\Services\Cloud\Nextcloud::enabled()) {
            try {
                $nc = new \App\Services\Cloud\Nextcloud();
                $nc->ensureFolder($nc->folder());
            } catch (\Throwable $e) {
                return back()->with('error', 'Сохранено, но папка не создана: ' . $e->getMessage());
            }
        }
        AdminAction::log('settings.update', 'облако');

        return back()->with('success', 'Настройки облака сохранены');
    }

    public function cloudDisconnect(): RedirectResponse
    {
        \App\Services\Cloud\Nextcloud::disconnect();
        AdminAction::log('settings.update', 'облако', 'отключено');

        return back()->with('success', 'Облако отключено — вложения снова уходят внутри писем');
    }

    /** Проверка: положить пробный файл и получить ссылку. */
    public function cloudTest(): RedirectResponse
    {
        try {
            $nc = new \App\Services\Cloud\Nextcloud();
            $tmp = tempnam(sys_get_temp_dir(), 'nc');
            file_put_contents($tmp, 'Проверка облака почтового сервера ' . config('areas.default_domain') . ' — ' . now()->format('d.m.Y H:i'));
            $r = $nc->publish($tmp, 'проверка.txt', 'admin');
            @unlink($tmp);
        } catch (\Throwable $e) {
            return back()->with('error', 'Проверка не прошла: ' . mb_substr($e->getMessage(), 0, 200));
        }

        return back()->with('success', 'Файл загружен, ссылка: ' . $r['url'] . ($r['expires'] ? ' (до ' . $r['expires'] . ')' : ''));
    }

    // ── вспомогательное ──────────────────────────────────────────────────
    private function mailHost(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host && ! filter_var($host, FILTER_VALIDATE_IP) ? $host : 'mail.' . config('areas.default_domain');
    }

    /** @return string[] */
    private function expectedNames(): array
    {
        $names = [];
        foreach (Domain::query()->pluck('domain') as $d) {
            foreach (['mail', 'imap', 'smtp', 'webmail', 'autoconfig', 'autodiscover'] as $sub) {
                $names[] = $sub . '.' . $d;
            }
        }

        return $names;
    }

    private function safe(callable $fn, mixed $default = null): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);

            return $default;
        }
    }
}
