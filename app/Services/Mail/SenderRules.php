<?php

namespace App\Services\Mail;

use App\Models\AppSetting;
use App\Models\SenderMark;
use App\Models\SenderRule;
use App\Models\Webmail\Label;
use App\Models\Webmail\RuleSet;
use App\Services\Server\Ctl;
use App\Services\Server\WbList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Решения сотрудников об отправителях.
 *
 *  «не спам»  → адрес/домен в общий белый список Amavis (для всех сразу: фильтр перестаёт трогать такие письма),
 *               личные правила «спам/рассылка» на него снимаются, старые письма из «Спама» возвращаются во «Входящие».
 *  «спам», «рассылка» → личное правило Sieve сотрудника (сразу), старая почта раскладывается по папкам;
 *               когда за тот же домен/адрес проголосовало нужное число сотрудников — правило становится общим:
 *               попадает в глобальный Sieve-скрипт Dovecot (sieve_before2) и действует для всех ящиков.
 */
class SenderRules
{
    public const KINDS = ['ham', 'spam', 'lists'];

    public const GLOBAL_SCRIPT = '/var/vmail/sieve/mailadmin-global.sieve';

    public function __construct(private readonly WbList $wblist, private readonly SieveBuilder $builder)
    {
    }

    public static function settings(): array
    {
        return AppSetting::group('senders');
    }

    /** Нормализовать значение: адрес → нижний регистр, домен → без «@». */
    public static function normalize(string $match, string $value): string
    {
        $value = strtolower(trim($value));

        return $match === 'domain' ? ltrim($value, '@') : $value;
    }

    /**
     * Отметить отправителя от имени сотрудника.
     *
     * @return array{global:bool,votes:int,threshold:int,removedGlobal:bool}
     */
    public function mark(string $user, string $kind, string $match, string $value, ?ImapSession $session = null, ?string $folder = null, ?string $folderName = null, bool $personalOnly = false): array
    {
        $user = strtolower($user);
        $value = self::normalize($match, $value);
        // «В свою папку» (и рассылка своего домена) — только личное правило, без голосов и общих списков.
        if ($kind === 'folder' || $personalOnly) {
            SenderMark::query()->where('user', $user)->where('value', $value)->whereIn('kind', ['spam', 'lists'])->delete();
            if ($session) {
                $this->syncPersonal($user, $session, $kind, $match, $value, $folder, $folderName);
            }

            return ['global' => false, 'votes' => 0, 'threshold' => 1, 'removedGlobal' => false];
        }
        $opposite = $kind === 'ham' ? ['spam', 'lists'] : ['ham', $kind === 'spam' ? 'lists' : 'spam'];

        SenderMark::query()->where('user', $user)->where('value', $value)->whereIn('kind', $opposite)->delete();
        SenderMark::query()->updateOrCreate(['user' => $user, 'kind' => $kind, 'value' => $value], ['match' => $match, 'created_at' => now()]);

        // Личный Sieve-скрипт: правило «отправитель → папка» (или снять такие правила при «не спам»).
        if ($session) {
            $this->syncPersonal($user, $session, $kind, $match, $value);
        }

        $s = self::settings();
        $result = ['global' => false, 'votes' => 0, 'threshold' => 1, 'removedGlobal' => false];
        if ($kind === 'ham') {
            $removed = SenderRule::query()->where('value', $value)->delete() > 0;
            if ($removed) {
                $this->pushGlobal();
            }
            $result['removedGlobal'] = $removed;
            if (! empty($s['ham_global'])) {
                $this->whitelist($match, $value, $user);
                $result['global'] = true;
            }

            return $result;
        }

        // Спам/рассылка: считаем голоса разных сотрудников.
        $votes = SenderMark::query()->where('kind', $kind)->where('value', $value)->distinct('user')->count('user');
        $threshold = max(1, (int) ($s[$kind . '_votes'] ?? 2));
        $result['votes'] = $votes;
        $result['threshold'] = $threshold;
        if ($votes >= $threshold) {
            $this->promote($kind, $match, $value, 'votes', $votes, null);
            $result['global'] = true;
        }

        return $result;
    }

    /** Сделать правило общим (по голосам или рукой администратора). */
    public function promote(string $kind, string $match, string $value, string $source, int $votes, ?string $by): void
    {
        $value = self::normalize($match, $value);
        SenderRule::query()->where('value', $value)->where('kind', '!=', $kind)->delete();
        SenderRule::query()->updateOrCreate(['kind' => $kind, 'value' => $value], [
            'match' => $match, 'source' => $source, 'votes' => $votes, 'created_by' => $by, 'created_at' => now(),
        ]);
        // Общий чёрный ход не нужен: если адрес был в белом списке Amavis — убираем, иначе спам-правило не сработает как ожидают.
        $this->unwhitelist($match, $value);
        $this->pushGlobal();
    }

    public function demote(SenderRule $rule): void
    {
        $rule->delete();
        $this->pushGlobal();
    }

    /** Текст глобального скрипта: спам → Junk, рассылки → Newsletters (если фильтр сам не счёл письмо спамом). */
    public function globalScript(): string
    {
        $rules = SenderRule::query()->orderBy('value')->get();
        $out = "require [\"fileinto\", \"mailbox\"];\n# Общие решения сотрудников о отправителях. Файл создаёт веб-почта — не редактировать вручную.\n";
        foreach (['spam' => 'fileinto "Junk";', 'lists' => 'fileinto :create "Newsletters";'] as $kind => $action) {
            $domains = $rules->where('kind', $kind)->where('match', 'domain')->pluck('value')->all();
            $addresses = $rules->where('kind', $kind)->where('match', 'address')->pluck('value')->all();
            $tests = [];
            if ($domains) {
                $tests[] = 'address :domain :is "from" ' . $this->list($domains);
            }
            if ($addresses) {
                $tests[] = 'address :all :is "from" ' . $this->list($addresses);
            }
            if (! $tests) {
                continue;
            }
            $test = count($tests) === 1 ? $tests[0] : 'anyof(' . implode(', ', $tests) . ')';
            if ($kind === 'lists') {
                $test = 'allof(not header :is "X-Spam-Flag" "YES", ' . $test . ')';
            }
            $out .= "\nif {$test} {\n    {$action}\n    stop;\n}\n";
        }

        return $out;
    }

    /** Записать глобальный скрипт на сервер (через служебную обёртку, файл владеет vmail). */
    public function pushGlobal(): void
    {
        $dir = storage_path('app/private/sieve');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $tmp = $dir . '/global.sieve';
        file_put_contents($tmp, $this->globalScript());
        chmod($tmp, 0644);
        try {
            Ctl::out('sieve-global', [$tmp]);
        } catch (\Throwable $e) {
            Log::warning('sieve-global: ' . $e->getMessage());
            throw new \RuntimeException('Общий скрипт не применился на сервере: ' . $e->getMessage());
        }
    }

    // ── Личные правила ────────────────────────────────────────────────
    public static function ruleId(string $kind, string $value): string
    {
        return 'sender:' . $kind . ':' . $value;
    }

    /** Правило в формате интерфейса: адрес/домен → папка. */
    public static function personalRule(string $kind, string $match, string $value, ?string $folder = null, ?string $folderName = null): array
    {
        $folder = $kind === 'folder' ? (string) $folder : ($kind === 'spam' ? 'Junk' : 'Newsletters');
        $title = $kind === 'folder' ? '«' . ($folderName ?: $folder) . '»' : ($kind === 'spam' ? 'Спам' : 'Рассылки');

        return [
            'id' => self::ruleId($kind, $value),
            'name' => ($match === 'domain' ? 'Все письма с ' . $value : 'Письма от ' . $value) . ' → ' . $title,
            'enabled' => true, 'match' => 'all', 'stop' => true,
            'conditions' => [['field' => 'from', 'op' => $match === 'domain' ? 'ends' : 'is', 'value' => $match === 'domain' ? '@' . $value : $value]],
            'actions' => [['type' => 'move', 'value' => $folder]],
        ];
    }

    private function syncPersonal(string $user, ImapSession $session, string $kind, string $match, string $value, ?string $folder = null, ?string $folderName = null): void
    {
        $set = RuleSet::find($user);
        $rules = $set?->rules ?? [];
        // У отправителя одно правило «куда класть»: новое заменяет прежнее (спам, рассылки или папка).
        $rules = array_values(array_filter($rules, fn ($r) => ! in_array($r['id'] ?? '', [self::ruleId('spam', $value), self::ruleId('lists', $value), self::ruleId('folder', $value)], true)));
        if ($kind !== 'ham') {
            // Правила по отправителю — в начало, чтобы сработали раньше остальных.
            array_unshift($rules, self::personalRule($kind, $match, $value, $folder, $folderName));
        }
        $labels = Label::where('user', $user)->pluck('name', 'id')->all();
        $script = $this->builder->build($rules, $set?->autoreply, $labels);
        $sieve = ManageSieveClient::forUser($session->loginName(), $session->password());
        $sieve->putScript(SieveBuilder::SCRIPT, $script);
        $sieve->setActive(SieveBuilder::SCRIPT);
        $sieve->logout();
        RuleSet::updateOrCreate(['user' => $user], ['rules' => $rules, 'autoreply' => $set?->autoreply, 'script' => $script]);
    }

    // ── Белый список Amavis ───────────────────────────────────────────
    private function whitelist(string $match, string $value, string $by): void
    {
        $pattern = $match === 'domain' ? '@' . $value : $value;
        // Чёрную запись на тот же адрес убираем — иначе белая с ней спорит.
        foreach ($this->wblist->all() as $w) {
            if ($w['email'] === $pattern && $w['wb'] === 'B') {
                $this->wblist->remove($w['id']);
            }
        }
        $this->wblist->add($pattern, 'W', 'не спам — ' . $by);
    }

    private function unwhitelist(string $match, string $value): void
    {
        $pattern = $match === 'domain' ? '@' . $value : $value;
        foreach ($this->wblist->all() as $w) {
            if ($w['email'] === $pattern && $w['wb'] === 'W') {
                $this->wblist->remove($w['id']);
            }
        }
    }

    // ── Пересортировка уже полученной почты ───────────────────────────
    /**
     * Разложить старые письма отправителя: спам/рассылка — из всех обычных папок в целевую,
     * «не спам» — из «Спама» во «Входящие». @return int сколько перемещено
     */
    public function resort(MailStore $store, string $kind, string $match, string $value, ?string $folder = null): int
    {
        $value = self::normalize($match, $value);
        $folders = $store->folders();
        if ($kind === 'ham') {
            $target = $store->rolePath('inbox');
            $sources = array_filter($folders, fn ($f) => $f['role'] === 'spam');
        } elseif ($kind === 'folder') {
            $target = (string) $folder;
            $sources = array_filter($folders, fn ($f) => ! in_array($f['role'], ['sent', 'drafts', 'trash', 'snoozed', 'shared', 'spam'], true));
        } else {
            $target = $store->rolePath($kind);
            $skip = ['sent', 'drafts', 'trash', 'snoozed', 'shared', $kind];
            $sources = array_filter($folders, fn ($f) => ! in_array($f['role'], $skip, true));
        }
        $moved = 0;
        foreach ($sources as $f) {
            if ($f['path'] === $target) {
                continue;
            }
            try {
                $uids = $store->searchSender($f['path'], $match, $value);
            } catch (\Throwable $e) {
                Log::info('resort ' . $f['path'] . ': ' . $e->getMessage());
                continue;
            }
            if ($uids) {
                if ($kind === 'spam') {
                    $store->flag($f['path'], $uids, '\\Seen', true);
                }
                $store->move($f['path'], $uids, $target);
                $moved += count($uids);
            }
        }

        return $moved;
    }

    // ── Сводки для админки ────────────────────────────────────────────
    /** Личные отметки, ещё не ставшие общими: кто и сколько. */
    public static function pending(): array
    {
        $global = SenderRule::query()->get()->keyBy(fn ($r) => $r->kind . '|' . $r->value);
        $white = [];
        try {
            foreach (app(WbList::class)->all() as $w) {
                if ($w['wb'] === 'W') {
                    $white[ltrim($w['email'], '@')] = true;
                }
            }
        } catch (\Throwable) {
        }
        $dismissed = array_flip((array) (AppSetting::group('senders_dismissed')['items'] ?? []));
        $rows = SenderMark::query()
            ->select('kind', 'match', 'value', DB::raw('count(distinct user) as votes'), DB::raw('group_concat(distinct user order by user separator ", ") as users'), DB::raw('max(created_at) as last_at'))
            ->groupBy('kind', 'match', 'value')->orderByDesc('last_at')->limit(300)->get();

        return $rows->filter(function ($r) use ($global, $white, $dismissed) {
            if (isset($dismissed[$r->kind . '|' . $r->value])) {
                return false;
            }

            return $r->kind === 'ham' ? ! isset($white[$r->value]) : ! $global->has($r->kind . '|' . $r->value);
        })
            ->map(fn ($r) => ['kind' => $r->kind, 'match' => $r->match, 'value' => $r->value, 'votes' => (int) $r->votes, 'users' => $r->users, 'lastAt' => $r->last_at ? substr((string) $r->last_at, 0, 16) : null])
            ->values()->all();
    }

    /** Администратор утвердил заявку: «не спам» → общий белый список, «спам»/«рассылка» → общее правило. */
    public function approve(string $kind, string $match, string $value, ?string $by): void
    {
        $value = self::normalize($match, $value);
        if ($kind === 'ham') {
            $removed = SenderRule::query()->where('value', $value)->delete() > 0;
            $this->whitelist($match, $value, 'заявка сотрудника, утвердил ' . ($by ?: 'администратор'));
            if ($removed) {
                $this->pushGlobal();
            }
        } else {
            $votes = SenderMark::query()->where('kind', $kind)->where('value', $value)->distinct('user')->count('user');
            $this->promote($kind, $match, $value, 'admin', $votes, $by);
        }
        $this->undismiss($kind, $value);
    }

    /** Отклонить заявку: у сотрудников остаются их личные правила, в списке заявок она больше не показывается. */
    public static function dismiss(string $kind, string $value): void
    {
        $items = (array) (AppSetting::group('senders_dismissed')['items'] ?? []);
        $items[] = $kind . '|' . strtolower($value);
        AppSetting::put('senders_dismissed', ['items' => array_values(array_unique(array_slice($items, -500)))]);
    }

    public static function undismiss(string $kind, string $value): void
    {
        $items = (array) (AppSetting::group('senders_dismissed')['items'] ?? []);
        AppSetting::put('senders_dismissed', ['items' => array_values(array_diff($items, [$kind . '|' . strtolower($value)]))]);
    }

    public static function pendingCount(): int
    {
        try {
            return count(self::pending());
        } catch (\Throwable) {
            return 0;
        }
    }

    private function list(array $values): string
    {
        return '[' . implode(', ', array_map(fn ($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"', $values)) . ']';
    }
}
