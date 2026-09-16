<?php

namespace App\Services\Server;

use App\Models\AppSetting;
use App\Models\SenderRule;
use App\Services\Mail\SenderRules;
use Illuminate\Support\Facades\Cache;

/**
 * Страница «Антиспам»: сводка за сутки, обучение Bayes, внешние базы, пороги.
 * Всё читается через mailadmin-ctl (sudo); тяжёлые вещи кэшируются на минуту.
 */
class Antispam
{
    public function __construct(private readonly AmavisConfig $amavis)
    {
    }

    /** Счётчики за сегодня и вчера из журналов. */
    public function stats(): array
    {
        return Cache::remember('antispam.stats', 60, function () {
            $j = json_decode(Ctl::out('spam-stats', [], 120), true);

            return is_array($j) ? $j : ['today' => [], 'yesterday' => []];
        });
    }

    /** База Bayes: сколько спама и нормы выучено. */
    public function bayes(): array
    {
        return Cache::remember('antispam.bayes', 60, function () {
            $out = ['nspam' => 0, 'nham' => 0, 'ntokens' => 0, 'ok' => false];
            try {
                foreach (preg_split('/\R/', Ctl::out('bayes-magic', [], 60)) as $line) {
                    if (preg_match('/^\s*0\.000\s+0\s+(\d+)\s+0\s+non-token data: (nspam|nham|ntokens)/', $line, $m)) {
                        $out[$m[2]] = (int) $m[1];
                        $out['ok'] = true;
                    }
                }
            } catch (\Throwable) {
            }
            // Bayes включается в оценку, только когда выучено хотя бы по 200 писем каждого рода.
            $out['active'] = $out['nspam'] >= 200 && $out['nham'] >= 200;

            return $out;
        });
    }

    /** Журнал обучения и очередь писем, ждущих обучения. */
    public function learning(): array
    {
        $log = [];
        try {
            foreach (preg_split('/\R/', trim(Ctl::out('salearn-log', [], 20))) as $line) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) (spam|ham): (.+)$/', $line, $m)) {
                    $log[] = ['at' => $m[1], 'kind' => $m[2], 'text' => $m[3]];
                }
            }
        } catch (\Throwable) {
        }
        $spool = ['spam' => 0, 'ham' => 0];
        try {
            if (preg_match('/spam (\d+) ham (\d+)/', Ctl::out('salearn-spool', [], 20), $m)) {
                $spool = ['spam' => (int) $m[1], 'ham' => (int) $m[2]];
            }
        } catch (\Throwable) {
        }

        return ['log' => array_slice(array_reverse($log), 0, 20), 'spool' => $spool];
    }

    /** Внешние базы: DNSBL, Razor, Pyzor, серый список, обновление правил. */
    public function net(): array
    {
        return Cache::remember('antispam.net', 300, function () {
            try {
                $j = json_decode(Ctl::out('spam-net', [], 90), true);
            } catch (\Throwable $e) {
                $j = null;
            }

            return is_array($j) ? $j : ['dnsbl' => [], 'threshold' => 0, 'razor' => false, 'pyzor' => false, 'greylist' => false, 'rulesUpdated' => 0, 'required' => 5.0];
        });
    }

    /** Пороги Amavis (tag2 = пометить спамом, kill = отбросить/в карантин). */
    public function levels(): array
    {
        try {
            return $this->amavis->current();
        } catch (\Throwable) {
            return ['tag' => 2, 'tag2' => 6.31, 'kill' => 6.31, 'cutoff' => 10];
        }
    }

    public function setLevels(float $tag2, float $kill): void
    {
        $this->amavis->setLevels(['tag2' => $tag2, 'kill' => $kill]);
        $this->amavis->apply();
    }

    /** Общие правила и заявки сотрудников — коротко, подробности на своих страницах. */
    public function rules(): array
    {
        $counts = SenderRule::query()->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind')->all();
        $senders = AppSetting::group('senders');

        return [
            'spam' => (int) ($counts['spam'] ?? 0),
            'lists' => (int) ($counts['lists'] ?? 0),
            'ham' => (int) ($counts['ham'] ?? 0),
            'pending' => SenderRules::pendingCount(),
            'spamVotes' => (int) ($senders['spam_votes'] ?? 2),
            'listsVotes' => (int) ($senders['lists_votes'] ?? 2),
        ];
    }

    /** Скормить очередь обучения прямо сейчас (обычно её разбирает cron раз в 5 минут). */
    public function learnNow(): string
    {
        Ctl::run('salearn-now', [], 600);
        Cache::forget('antispam.bayes');

        return trim((string) (Ctl::run('salearn-log', [], 20)[1] ?? ''));
    }

    public function forget(): void
    {
        Cache::forget('antispam.stats');
        Cache::forget('antispam.bayes');
        Cache::forget('antispam.net');
    }
}
