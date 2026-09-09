<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Models\AppSetting;
use App\Models\Backup;
use App\Models\User;
use App\Models\Vmail\Mailbox;
use App\Models\Webmail\Setting;
use App\Services\ServerHealth;
use App\Services\Server\Certificate;
use App\Services\Server\DnsCheck;
use App\Services\Server\Disk;
use App\Services\Server\Fail2ban;
use App\Services\Server\MailLog;
use App\Services\Server\PostfixQueue;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/** Обзор: цифры за сутки, график, «требует внимания», службы, активные адреса, действия админов. */
class DashboardController extends Controller
{
    public function __invoke(ServerHealth $health, MailLog $log, PostfixQueue $queue, Certificate $cert, Disk $disk, Fail2ban $f2b): Response
    {
        $stats = $log->readable() ? $log->stats() : null;
        $q = $queue->all();
        $oldest = $q ? max(array_map(fn ($r) => (int) ($r['age'] ?? 0), $q)) : 0;
        $certInfo = $cert->info();
        $space = $disk->vmail();
        $lastBackup = Backup::query()->orderByDesc('id')->first();
        $attention = $this->attention($certInfo, $space, $lastBackup, $q, $oldest);

        return Inertia::render('Dashboard', [
            'today' => now()->locale('ru')->translatedFormat('j F, l · H:i'),
            'tiles' => [
                ['value' => $stats ? number_format($stats['delivered'], 0, ',', ' ') : '—', 'label' => 'Доставлено', 'sub' => 'за сутки'],
                ['value' => $stats ? $stats['spam'] : '—', 'label' => 'Спам и отказы', 'sub' => $stats && $stats['delivered'] ? round($stats['spam'] / max(1, $stats['delivered'] + $stats['spam']) * 100) . '% входящих' : ''],
                ['value' => count($q), 'label' => 'В очереди', 'sub' => $oldest ? 'старейшее ' . $this->age($oldest) : 'пусто', 'kind' => count($q) > 20 || $oldest > 7200 ? 'warn' : ''],
                ['value' => $space ? round($space['used'] / 1073741824) . ' ГБ' : '—', 'label' => $space ? 'Занято из ' . round($space['size'] / 1073741824) : 'Диск', 'sub' => $space ? $space['pct'] . '% · ' . Mailbox::count() . ' ящиков' : '', 'kind' => $space && $space['pct'] > 85 ? 'warn' : ''],
                ['value' => $certInfo ? $certInfo['daysLeft'] . ' дн' : '—', 'label' => 'Сертификат', 'sub' => $certInfo ? ($certInfo['daysLeft'] < 14 ? 'продление не сработало?' : 'продление автоматически') : '', 'kind' => $certInfo && $certInfo['daysLeft'] < 14 ? 'warn' : ''],
            ],
            'hours' => $stats['hours'] ?? [],
            'attention' => $attention,
            'services' => $this->services($health, $f2b),
            'top' => $stats['top'] ?? [],
            'actions' => AdminAction::query()->orderByDesc('id')->limit(6)->get()->map(fn (AdminAction $a) => ['ts' => $a->created_at->isToday() ? $a->created_at->format('H:i') : $a->created_at->locale('ru')->translatedFormat('j M'), 'text' => $a->actor . ' ' . LogsController::describe($a)]),
        ]);
    }

    /** @return array<int,array{kind:string,text:string,href:?string}> */
    private function attention(?array $cert, ?array $space, ?Backup $backup, array $queue, int $oldest): array
    {
        $out = [];
        $domain = config('areas.default_domain');
        $ptr = Cache::remember('dns.ptr.' . $domain, 600, fn () => (new DnsCheck())->ptr($domain));
        if ($ptr && $ptr['kind'] === 'no') {
            $out[] = ['kind' => 'no', 'text' => 'PTR для ' . $ptr['ip'] . ' не настроен — часть серверов может отклонять письма', 'href' => '/settings/domains'];
        }
        $adminsNo2fa = User::query()->where('is_active', 1)->get()->filter(fn (User $u) => ! $u->hasTwoFactor())->count();
        if ($adminsNo2fa > 0) {
            $out[] = ['kind' => 'warn', 'text' => $adminsNo2fa . ' ' . $this->plural($adminsNo2fa, 'администратор', 'администратора', 'администраторов') . ' без двухфакторной защиты', 'href' => '/settings/admins'];
        }
        $no2fa = Mailbox::query()->people()->count() - Setting::query()->whereRaw("JSON_EXTRACT(data, '$.totp_enabled') = true")->count();
        if ($no2fa > 0) {
            $out[] = ['kind' => 'warn', 'text' => $no2fa . ' ' . $this->plural($no2fa, 'сотрудник', 'сотрудника', 'сотрудников') . ' без двухфакторной защиты', 'href' => '/security/twofa'];
        }
        if (($n = \App\Services\Mail\SenderRules::pendingCount()) > 0) {
            $out[] = ['kind' => 'warn', 'text' => 'Заявок от сотрудников по отправителям (спам, рассылки, не спам): ' . $n, 'href' => '/settings/spam'];
        }
        if (! $backup) {
            $out[] = ['kind' => 'warn', 'text' => 'Резервных копий ещё не было — настройте расписание', 'href' => '/settings/backup'];
        } elseif ($backup->status !== 'ok') {
            $out[] = ['kind' => 'warn', 'text' => 'Резервная копия ' . $backup->created_at->locale('ru')->translatedFormat('j F') . ' не удалась: ' . mb_substr((string) $backup->error, 0, 80), 'href' => '/settings/backup'];
        } elseif ($backup->created_at->lt(now()->subDays(2))) {
            $out[] = ['kind' => 'warn', 'text' => 'Последняя копия — ' . $backup->created_at->locale('ru')->translatedFormat('j F'), 'href' => '/settings/backup'];
        }
        if ($oldest > 7200) {
            $out[] = ['kind' => 'warn', 'text' => 'Письмо висит в очереди ' . $this->age($oldest), 'href' => '/queue'];
        }
        if ($space && $space['pct'] > 85) {
            $out[] = ['kind' => 'no', 'text' => 'Диск с почтой занят на ' . $space['pct'] . '%', 'href' => null];
        }
        if ($cert) {
            $out[] = $cert['daysLeft'] < 14
                ? ['kind' => 'no', 'text' => 'Сертификат истекает через ' . $cert['daysLeft'] . ' дн — продление не сработало', 'href' => '/settings/cert']
                : ['kind' => 'ok', 'text' => 'Сертификат продлится автоматически ' . $cert['renewAt'], 'href' => '/settings/cert'];
        }

        return $out;
    }

    /** @return array<int,array{name:string,status:string,kind:string}> */
    private function services(ServerHealth $health, Fail2ban $f2b): array
    {
        $list = array_values(array_filter($health->services(), fn ($s) => ! str_starts_with($s['name'], 'SOGo')));
        $bans = $f2b->bannedCount();
        $list[] = ['name' => 'fail2ban — защита от перебора', 'status' => $bans === null ? 'нет данных' : ($bans ? $bans . ' ' . $this->plural($bans, 'блокировка', 'блокировки', 'блокировок') : 'блокировок нет'), 'kind' => $bans === null ? 'off' : 'ok'];
        $lastRun = Cache::get('scheduler.last_run');
        $list[] = ['name' => 'Планировщик приложения', 'status' => $lastRun ? 'сработал ' . date('H:i', $lastRun) : 'ещё не запускался', 'kind' => $lastRun && time() - $lastRun < 180 ? 'ok' : 'warn'];

        return $list;
    }

    private function age(int $sec): string
    {
        if ($sec < 3600) {
            return round($sec / 60) . ' мин';
        }

        return $sec < 86400 ? round($sec / 3600) . ' ч' : round($sec / 86400) . ' дн';
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $m10 = $n % 10;
        $m100 = $n % 100;
        if ($m10 === 1 && $m100 !== 11) {
            return $one;
        }

        return $m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20) ? $few : $many;
    }
}
