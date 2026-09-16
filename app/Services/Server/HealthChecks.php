<?php

namespace App\Services\Server;

use App\Models\AppSetting;
use App\Models\Backup;
use App\Models\Vmail\Mailbox;
use App\Services\FeedbackNotifier;
use App\Services\ServerHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Страница «Состояние»: плитки с нагрузкой и список проверок «зелёная / жёлтая / красная» с подсказкой, что делать.
 * Каждая проверка отвечает за то, что за последнюю неделю ломалось молча: обучение спама, перезапуск Dovecot,
 * планировщик, бэкап, DNS-списки, диск.
 */
class HealthChecks
{
    public function __construct(
        private readonly ServerHealth $health,
        private readonly Disk $disk,
        private readonly Certificate $cert,
        private readonly Antispam $antispam,
        private readonly PostfixQueue $queue,
    ) {
    }

    /** Сырые данные сервера (нагрузка, память, счётчики) — через mailadmin-ctl, кэш на минуту. */
    public function sysinfo(): array
    {
        return Cache::remember('health.sysinfo', 60, function () {
            try {
                $j = json_decode(Ctl::out('sysinfo', [], 60), true);
            } catch (\Throwable) {
                $j = null;
            }

            return is_array($j) ? $j : [];
        });
    }

    /** @return array<int,array{value:string,label:string,sub:string,kind:string}> */
    public function tiles(array $si): array
    {
        $load = $si['load'][0] ?? null;
        $cores = max(1, (int) ($si['cores'] ?? 1));
        $mem = $si['mem'] ?? null;
        $vmail = $this->disk->vmail();
        $root = $this->disk->root();
        $gb = fn (int $b) => round($b / 1073741824);

        return [
            ['value' => $load === null ? '—' : number_format($load, 2), 'label' => 'Нагрузка', 'sub' => "ядер {$cores}" . (isset($si['load'][1]) ? ' · 5 мин ' . number_format($si['load'][1], 2) : ''), 'kind' => $load !== null && $load > $cores ? 'warn' : ''],
            ['value' => $mem ? round(($mem['total'] - $mem['avail']) / 1024, 1) . ' ГБ' : '—', 'label' => $mem ? 'Память из ' . round($mem['total'] / 1024) . ' ГБ' : 'Память', 'sub' => $mem ? 'доступно ' . round($mem['avail'] / 1024, 1) . ' ГБ' . (($si['swap']['used'] ?? 0) > 512 ? ' · своп ' . $si['swap']['used'] . ' МБ' : '') : '', 'kind' => $mem && $mem['avail'] < 1024 ? 'warn' : ''],
            ['value' => $vmail ? $vmail['pct'] . '%' : '—', 'label' => 'Диск почты', 'sub' => $vmail ? $gb($vmail['used']) . ' из ' . $gb($vmail['size']) . ' ГБ' : '', 'kind' => $vmail && $vmail['pct'] > 85 ? 'no' : ($vmail && $vmail['pct'] > 75 ? 'warn' : '')],
            ['value' => $root ? $root['pct'] . '%' : '—', 'label' => 'Системный диск', 'sub' => $root ? 'свободно ' . $gb($root['avail']) . ' ГБ' : '', 'kind' => $root && $root['pct'] > 90 ? 'no' : ($root && $root['pct'] > 80 ? 'warn' : '')],
            ['value' => (string) ($si['queue'] ?? '—'), 'label' => 'В очереди Postfix', 'sub' => ($si['imapUsers'] ?? null) !== null ? 'IMAP: ' . $si['imapUsers'] . ' чел., ' . $si['imapConnections'] . ' соед.' : '', 'kind' => ($si['queue'] ?? 0) > 20 ? 'warn' : ''],
            ['value' => isset($si['uptime']) ? $this->days((int) $si['uptime']) : '—', 'label' => 'Без перезагрузки', 'sub' => ($si['rebootRequired'] ?? false) ? 'ждёт перезагрузки' : (($si['updates'] ?? 0) ? 'обновлений: ' . $si['updates'] : 'обновлений нет'), 'kind' => ($si['rebootRequired'] ?? false) || ($si['securityUpdates'] ?? 0) ? 'warn' : ''],
        ];
    }

    /**
     * Список проверок по группам.
     * @return array<int,array{group:string,title:string,kind:string,text:string,hint:?string,href:?string}>
     */
    public function checks(array $si): array
    {
        $c = [];
        $add = function (string $group, string $title, string $kind, string $text, ?string $hint = null, ?string $href = null) use (&$c) {
            $c[] = compact('group', 'title', 'kind', 'text', 'hint', 'href');
        };

        // ── Службы ──
        foreach ($this->health->services() as $s) {
            $add('Службы', $s['name'], $s['kind'] === 'off' ? 'off' : $s['kind'], $s['status'], $s['kind'] === 'no' ? 'systemctl restart <служба> и journalctl -u <служба>' : null);
        }
        $add('Службы', 'fail2ban — защита от подбора', ($si['fail2ban'] ?? false) ? 'ok' : 'warn', ($si['fail2ban'] ?? false) ? 'работает' : 'не отвечает', null, '/security');

        // ── Почта ──
        $q = (int) ($si['queue'] ?? 0);
        $add('Почта', 'Очередь Postfix', $q > 50 ? 'no' : ($q > 10 ? 'warn' : 'ok'), $q ? "{$q} писем ждут отправки" : 'пусто', $q > 10 ? 'Посмотрите причины задержек в очереди' : null, '/queue');
        $pfF = (int) ($si['postfixFatal'] ?? 0);
        $pfW = (int) ($si['postfixWarnings'] ?? 0);
        $add('Почта', 'Журнал Postfix за сегодня', $pfF ? 'no' : ($pfW > 200 ? 'warn' : 'ok'), $pfF ? "{$pfF} критических, {$pfW} предупреждений" : "{$pfW} предупреждений, критических нет", $pfF ? 'journalctl -u postfix или страница «Журналы»' : null, '/logs');
        $dE = (int) ($si['dovecotErrors'] ?? 0);
        $add('Почта', 'Журнал Dovecot за сегодня', $dE > 20 ? 'no' : ($dE ? 'warn' : 'ok'), $dE ? "{$dE} ошибок" : 'ошибок нет', $dE ? '/var/log/dovecot/dovecot.log — чаще всего индексация или права на папки' : null);
        $idx = (int) ($si['indexers'] ?? 0);
        $add('Почта', 'Индексация поиска', $idx > 4 ? 'warn' : 'ok', $idx ? "{$idx} воркеров заняты" : 'очередь пуста', $idx > 4 ? 'Много воркеров днём замедляют диск; ночная доиндексация в 21:00' : null);
        $timer = (string) ($si['indexTimerLast'] ?? '');
        $add('Почта', 'Ночная доиндексация', $timer !== '' && $timer !== 'n/a' ? 'ok' : 'warn', $timer !== '' && $timer !== 'n/a' ? 'последний запуск ' . $this->ru($timer) : 'таймер не запускался', $timer === '' ? 'Таймера нет — запустите deploy/dovecot-fts-learn.sh, он создаёт mailadmin-index-nightly.timer' : null);

        // ── Антиспам ──
        $bayes = $this->antispam->bayes();
        $add('Антиспам', 'Обученность фильтра (Bayes)', $bayes['active'] ? 'ok' : 'warn', "спам {$bayes['nspam']}, норма {$bayes['nham']}", $bayes['active'] ? null : 'Нужно по 200 писем каждого рода — скормите папки «Спам» и «Отправленные»', '/antispam');
        $learn = $this->antispam->learning();
        $pending = $learn['spool']['spam'] + $learn['spool']['ham'];
        $lastLearn = $learn['log'][0]['at'] ?? null;
        $stale = $pending > 0 && (! $lastLearn || strtotime($lastLearn) < time() - 3600);
        $add('Антиспам', 'Очередь обучения', $stale ? 'no' : 'ok', $pending ? "{$pending} писем ждут" . ($lastLearn ? ', последнее обучение ' . $lastLearn : '') : 'пусто', $stale ? 'cron mailadmin-salearn не разбирает очередь — смотрите /var/log/mailadmin-salearn.log' : null, '/antispam');
        $add('Антиспам', 'Cron обучения установлен', ($si['salearnCron'] ?? false) ? 'ok' : 'no', ($si['salearnCron'] ?? false) ? '/etc/cron.d/mailadmin-salearn' : 'файла нет', ($si['salearnCron'] ?? false) ? null : 'Запустите deploy/dovecot-fts-learn.sh');
        $net = $this->antispam->net();
        $dead = array_values(array_filter($net['dnsbl'] ?? [], fn ($d) => ! $d['ok']));
        $add('Антиспам', 'Чёрные списки отвечают', $dead ? 'warn' : 'ok', $dead ? 'не отвечают: ' . implode(', ', array_column($dead, 'host')) : count($net['dnsbl'] ?? []) . ' списков, все отвечают', $dead ? 'Проверьте резолвер (unbound) — без ответа список просто не участвует' : null, '/antispam');
        $add('Антиспам', 'Razor и Pyzor', ($net['razor'] ?? false) && ($net['pyzor'] ?? false) ? 'ok' : 'warn', (($net['razor'] ?? false) ? 'Razor зарегистрирован' : 'Razor не зарегистрирован') . ', ' . (($net['pyzor'] ?? false) ? 'Pyzor отвечает' : 'Pyzor не отвечает'), null, '/antispam');
        $ru = (int) ($net['rulesUpdated'] ?? 0);
        $add('Антиспам', 'Правила SpamAssassin', $ru && $ru > time() - 7 * 86400 ? 'ok' : 'warn', $ru ? 'обновлены ' . date('d.m.Y', $ru) : 'дата обновления неизвестна', $ru && $ru <= time() - 7 * 86400 ? 'sa-update не запускался неделю — проверьте таймер spamassassin-maintenance' : null);

        // ── Планировщик и фоновые задачи ──
        $last = (int) Cache::get('scheduler.last_run', 0);
        $add('Фоновые задачи', 'Планировщик Laravel', $last > time() - 180 ? 'ok' : 'no', $last ? 'последний тик ' . date('H:i:s', $last) : 'ещё не запускался', $last > time() - 180 ? null : 'cron schedule:run не работает — не будет отложенных писем, напоминаний, обращений по почте');
        $add('Фоновые задачи', 'Cron планировщика', ($si['schedulerCron'] ?? false) ? 'ok' : 'no', ($si['schedulerCron'] ?? false) ? 'запись есть' : 'записи schedule:run нет', ($si['schedulerCron'] ?? false) ? null : 'Добавьте в /etc/cron.d: * * * * * www-data php /opt/mailadmin/artisan schedule:run');
        foreach (['feedback_import' => ['Ответы на обращения по почте', 'feedback:import раз в минуту'], 'shares_sync' => ['Права общего доступа', 'shares:sync раз в 10 минут'], 'threads_sync' => ['Индекс цепочек', 'threads:sync раз в 10 минут']] as $key => [$title, $what]) {
            $t = (int) Cache::get('heartbeat.' . $key, 0);
            $limit = $key === 'feedback_import' ? 300 : 1800;
            $add('Фоновые задачи', $title, $t > time() - $limit ? 'ok' : ($t ? 'warn' : 'off'), $t ? 'последний запуск ' . date('H:i', $t) : 'ещё не отмечался', $t && $t <= time() - $limit ? "{$what} — не отработал вовремя, смотрите storage/logs" : null);
        }
        $fb = FeedbackNotifier::mailbox();
        $fbOk = Mailbox::query()->where('username', $fb)->where('active', 1)->exists();
        $add('Фоновые задачи', 'Ящик обращений ' . $fb, $fbOk ? 'ok' : 'warn', $fbOk ? 'есть' : 'не создан — ответы письмом не принимаются', $fbOk ? null : 'Создастся сам при первом запуске feedback:import');

        // ── Резервные копии и обслуживание ──
        $b = Backup::query()->orderByDesc('id')->first();
        if (! $b) {
            $add('Обслуживание', 'Резервная копия', 'warn', 'ещё не делалась', 'Настройте расписание', '/settings/backup');
        } else {
            $age = $b->created_at->diffInHours(now());
            $kind = $b->status !== 'ok' ? 'no' : ($age > 36 ? 'warn' : 'ok');
            $add('Обслуживание', 'Резервная копия', $kind, ($b->status === 'ok' ? 'успешно ' : 'ошибка ') . $b->created_at->locale('ru')->translatedFormat('j F H:i') . ($b->size ? ' · ' . round($b->size / 1048576) . ' МБ' : ''), $kind === 'ok' ? null : ($b->status !== 'ok' ? mb_substr((string) $b->error, 0, 120) : 'Старше полутора суток — проверьте расписание'), '/settings/backup');
        }
        $cert = $this->cert->info();
        $add('Обслуживание', 'Сертификат HTTPS/IMAP/SMTP', $cert ? ($cert['daysLeft'] < 14 ? 'no' : ($cert['daysLeft'] < 30 ? 'warn' : 'ok')) : 'off', $cert ? 'действует ещё ' . $cert['daysLeft'] . ' дн' : 'нет данных', $cert && $cert['daysLeft'] < 14 ? 'Автопродление не сработало — /settings/cert' : null, '/settings/cert');
        $add('Обслуживание', 'Время синхронизировано (NTP)', ($si['ntp'] ?? '') === 'yes' ? 'ok' : 'warn', ($si['ntp'] ?? '') === 'yes' ? 'да' : 'нет: ' . ($si['ntp'] ?? 'неизвестно'), ($si['ntp'] ?? '') === 'yes' ? null : 'timedatectl set-ntp true — иначе поедут даты писем и DKIM');
        $upd = (int) ($si['updates'] ?? 0);
        $sec = (int) ($si['securityUpdates'] ?? 0);
        $add('Обслуживание', 'Обновления системы', $sec ? 'warn' : 'ok', $upd ? "{$upd} пакетов" . ($sec ? ", из них по безопасности {$sec}" : '') : 'всё обновлено', $sec ? 'apt upgrade в окно обслуживания' : null);
        $add('Обслуживание', 'Перезагрузка', ($si['rebootRequired'] ?? false) ? 'warn' : 'ok', ($si['rebootRequired'] ?? false) ? 'требуется после обновления ядра' : 'не требуется' . (isset($si['uptime']) ? ', работает ' . $this->days((int) $si['uptime']) : ''), ($si['rebootRequired'] ?? false) ? 'Перезагрузите в нерабочее время' : null);
        $errs = $this->appErrorsToday();
        // Красное — только если ошибки идут прямо сейчас; старые за день — жёлтое напоминание
        $add('Обслуживание', 'Ошибки веб-почты', $errs['recent'] > 5 ? 'no' : ($errs['recent'] ? 'warn' : ($errs['n'] ? 'off' : 'ok')), $errs['n'] ? "за день {$errs['n']}, за последний час {$errs['recent']}. Чаще всего: " . $errs['top'] : 'нет', $errs['n'] ? 'storage/logs/laravel-' . date('Y-m-d') . '.log' : null);

        // ── Ящики ──
        $near = $this->quotaNearLimit();
        $add('Ящики', 'Заполненность ящиков', $near ? 'warn' : 'ok', $near ? 'близко к квоте: ' . implode(', ', array_slice($near, 0, 5)) . (count($near) > 5 ? ' и ещё ' . (count($near) - 5) : '') : 'все в пределах квоты', $near ? 'Увеличьте квоту в карточке сотрудника или попросите почистить' : null, '/mailboxes');
        $blocked = \App\Models\EmployeeProfile::query()->where('login_blocked', true)->count();
        $add('Ящики', 'Заблокированные ящики', 'off', $blocked ? "{$blocked}, почта в них копится" : 'нет', $blocked ? 'Уволенных со временем стоит архивировать и удалить' : null, '/mailboxes?filter=blocked');

        return $c;
    }

    /** Ошибки приложения за сегодня: сколько и самая частая. */
    private function appErrorsToday(): array
    {
        $file = storage_path('logs/laravel-' . date('Y-m-d') . '.log');
        if (! is_readable($file)) {
            return ['n' => 0, 'recent' => 0, 'top' => ''];
        }
        $n = 0;
        $recent = 0;
        $since = date('Y-m-d H:i', time() - 3600);
        $top = [];
        $h = fopen($file, 'r');
        while (($line = fgets($h)) !== false) {
            if (str_contains($line, 'production.ERROR:')) {
                $n++;
                if (substr($line, 1, 16) >= $since) {
                    $recent++;
                }
                $msg = mb_substr(trim(substr($line, strpos($line, 'production.ERROR:') + 18)), 0, 70);
                $msg = preg_replace('/\s*\{.*$/', '', $msg) ?: $msg;
                $top[$msg] = ($top[$msg] ?? 0) + 1;
            }
        }
        fclose($h);
        arsort($top);

        return ['n' => $n, 'recent' => $recent, 'top' => $top ? array_key_first($top) : ''];
    }

    /** Ящики, занятые больше чем на 90 % квоты (used_quota ведёт Dovecot). @return string[] */
    private function quotaNearLimit(): array
    {
        try {
            $rows = DB::connection('vmail')->table('mailbox as m')->join('used_quota as u', 'u.username', '=', 'm.username')
                ->where('m.quota', '>', 0)->whereRaw('u.bytes > m.quota * 1048576 * 0.9')
                ->select('m.username', 'm.quota', 'u.bytes')->orderByDesc('u.bytes')->limit(20)->get();

            return $rows->map(fn ($r) => strstr($r->username, '@', true) . ' ' . round($r->bytes / 1048576 / max(1, $r->quota) * 100) . '%')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function days(int $sec): string
    {
        $d = intdiv($sec, 86400);
        $h = intdiv($sec % 86400, 3600);

        return $d ? "{$d} дн {$h} ч" : "{$h} ч";
    }

    private function ru(string $systemdTime): string
    {
        $t = strtotime($systemdTime);

        return $t ? date('d.m H:i', $t) : $systemdTime;
    }
}
