<?php

namespace App\Services\Server;

use App\Models\AppSetting;
use App\Models\Backup;
use App\Services\Mail\ImapSession;
use App\Services\ServerHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Уведомления администраторам: почта и Telegram. Проверки — по расписанию (alerts:check),
 * одно и то же событие не повторяется чаще раза в 6 часов.
 */
class Alerts
{
    public function __construct(private readonly PostfixQueue $queue, private readonly Disk $disk, private readonly ServerHealth $health, private readonly Certificate $cert)
    {
    }

    /** Проверить всё и разослать, что нужно. @return string[] отправленные тексты */
    public function check(): array
    {
        $a = AppSetting::group('alerts');
        $sent = [];
        $fire = function (string $key, string $text) use (&$sent) {
            if (Cache::add('alert.' . $key, 1, 6 * 3600)) {
                $this->send('⚠️ ' . $text);
                $sent[] = $text;
            }
        };

        if ($a['queue']) {
            $q = $this->queue->all();
            $oldest = $q ? max(array_map(fn ($r) => (int) ($r['age'] ?? 0), $q)) : 0;
            if (count($q) >= (int) $a['queue_size']) {
                $fire('queue.size', 'В очереди ' . count($q) . ' писем (порог ' . $a['queue_size'] . ')');
            }
            if ($oldest >= (int) $a['queue_age_hours'] * 3600) {
                $fire('queue.age', 'Письмо висит в очереди ' . round($oldest / 3600, 1) . ' ч — проверьте доставку');
            }
        }
        if ($a['disk']) {
            $d = $this->disk->vmail();
            if ($d && $d['pct'] >= (int) $a['disk_pct']) {
                $fire('disk', 'Диск с почтой занят на ' . $d['pct'] . '% (свободно ' . round($d['avail'] / 1073741824) . ' ГБ)');
            }
        }
        if ($a['services']) {
            foreach ($this->health->services() as $s) {
                if ($s['kind'] === 'no') {
                    $fire('service.' . md5($s['name']), 'Служба остановлена: ' . $s['name']);
                }
            }
            // Базы ClamAV берутся с нашего зеркала; если оно недоступно, freshclam молча живёт на старых базах.
            $daily = glob('/var/lib/clamav/daily.c?d') ?: [];
            if ($daily && ($age = (time() - (int) filemtime($daily[0])) / 86400) > 3 && $this->health->unitState('clamav-daemon') === 'active') {
                $fire('clamav.stale', 'Базы ClamAV не обновлялись ' . (int) $age . ' дн — проверьте зеркало баз и журнал freshclam');
            }
            $c = $this->cert->info();
            if ($c && $c['daysLeft'] < 14) {
                $fire('cert', 'Сертификат истекает через ' . $c['daysLeft'] . ' дн — автопродление не сработало');
            }
        }
        if ($a['backup']) {
            $last = Backup::query()->orderByDesc('id')->first();
            if ($last && $last->status !== 'ok') {
                $fire('backup.failed.' . $last->id, 'Резервная копия не удалась: ' . mb_substr((string) $last->error, 0, 120));
            } elseif ($last && $last->created_at->lt(now()->subHours(36))) {
                $fire('backup.stale', 'Резервной копии не было больше 36 часов (последняя ' . $last->created_at->format('d.m H:i') . ')');
            }
        }

        return $sent;
    }

    /** Ежедневная сводка. */
    public function digest(MailLog $log): void
    {
        if (! (AppSetting::group('alerts')['digest'] ?? false)) {
            return;
        }
        $stats = $log->readable() ? $log->stats() : null;
        $q = count($this->queue->all());
        $d = $this->disk->vmail();
        $c = $this->cert->info();
        $lines = [
            '📬 Почта ' . config('areas.default_domain') . ' — сводка за сутки',
            'Доставлено: ' . ($stats['delivered'] ?? '—') . ', спам и отказы: ' . ($stats['spam'] ?? '—') . ', неверных паролей: ' . ($stats['authFail'] ?? '—'),
            'В очереди: ' . $q . ' · диск: ' . ($d ? $d['pct'] . '%' : '—') . ' · сертификат: ' . ($c ? $c['daysLeft'] . ' дн' : '—'),
        ];
        $down = array_filter($this->health->services(), fn ($s) => $s['kind'] === 'no');
        if ($down) {
            $lines[] = 'Остановлено: ' . implode(', ', array_map(fn ($s) => explode(' — ', $s['name'])[0], $down));
        }
        $this->send(implode("\n", $lines));
    }

    public function send(string $text, bool $securityOnly = false): void
    {
        // Копия в раздел «Отчёты» — чтобы история уведомлений не зависела от почтового ящика администратора.
        try {
            app(SystemReports::class)->save(str_contains($text, 'сводка за сутки') ? 'digest' : 'alerts', $text);
        } catch (\Throwable $e) {
            Log::warning('Отчёт не сохранён: ' . $e->getMessage());
        }
        $ch = AppSetting::group('channels');
        $emails = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $ch['emails']))));
        foreach ($emails as $to) {
            try {
                (new Mailer(ImapSession::smtpLocal()))->send((new Email())
                    ->from(new Address('noreply@' . config('areas.default_domain'), 'Почта ' . config('areas.default_domain')))
                    ->to(new Address($to))->subject(mb_substr(preg_replace('/^\W+/u', '', explode("\n", $text)[0]), 0, 120))->text($text));
            } catch (\Throwable $e) {
                Log::warning('Уведомление по почте не ушло', ['to' => $to, 'error' => $e->getMessage()]);
            }
        }
        $this->telegram($text);
    }

    public function telegram(string $text): bool
    {
        $ch = AppSetting::group('channels');
        if (! filled($ch['telegram_token']) || ! filled($ch['telegram_chat'])) {
            return false;
        }
        try {
            $client = Http::timeout(8);
            if (filled($ch['telegram_proxy'])) {
                $client = $client->withOptions(['proxy' => $ch['telegram_proxy']]);
            }
            $r = $client->post('https://api.telegram.org/bot' . $ch['telegram_token'] . '/sendMessage', ['chat_id' => $ch['telegram_chat'], 'text' => $text, 'disable_web_page_preview' => true]);

            return $r->ok();
        } catch (\Throwable $e) {
            Log::warning('Telegram недоступен', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
