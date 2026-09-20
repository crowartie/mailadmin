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

    /** Запись TXT с открытым ключом подписи: её нужно прописать в DNS домена. */
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
            'externalSenders' => \App\Models\ExternalSender::query()->orderBy('address')->get()->map(fn ($s) => [
                'id' => $s->id, 'address' => $s->address, 'provider' => $s->provider, 'note' => $s->note, 'by' => $s->created_by, 'at' => $s->created_at?->format('d.m.Y'),
            ]),
            'externalProviders' => \App\Services\Server\ExternalSenders::labels(),
            'externalRanges' => \App\Services\Server\ExternalSenders::cachedCounts(),
        ];
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

    // ── Файлы: Nextcloud для больших вложений ────────────────────────────
    private function cloudData(): array
    {
        $s = \App\Services\Cloud\Nextcloud::settings();
        unset($s['app_password']);
        $s['connected'] = filled($s['login']);

        $files = \App\Services\Cloud\LocalFiles::settings() + [
            'ready' => \App\Http\Controllers\Mail\FilesController::ready(),
            'root' => \App\Services\Cloud\LocalFiles::root(),
            'count' => $this->safe(fn () => \App\Models\Webmail\CloudFile::query()->count(), 0),
            'used' => $this->safe(fn () => (int) \App\Models\Webmail\CloudFile::query()->sum('size'), 0),
        ];

        return ['cloud' => $s, 'files' => $files, 'cloudStatus' => $s['connected'] ? $this->safe(fn () => (new \App\Services\Cloud\Nextcloud())->status()) : null, 'sizeLimitMb' => $this->safe(fn () => $this->amavis->current()['sizeLimitMb'], 15)];
    }

    /** Шаг 1: адрес облака → ссылка для входа (Login Flow v2). */
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
