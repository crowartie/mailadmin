<?php

namespace App\Http\Controllers;

use App\Models\EmployeeProfile;
use App\Models\ExternalSender;
use App\Models\SenderRule;
use App\Models\Vmail\Domain;
use App\Models\Vmail\Forwarding;
use App\Models\Vmail\Mailbox;
use App\Services\Mail\FolderShares;
use App\Services\Server\WbList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Проверить адрес»: куда придёт письмо на этот адрес и что с ним сделают по дороге —
 * ящик, псевдонимы, пересылки, рассылки, правила Sieve, общий доступ, внешние отправители, общие правила.
 */
class TraceController extends Controller
{
    public function index(Request $request): Response
    {
        $address = strtolower(trim((string) $request->query('address', '')));

        return Inertia::render('Trace/Index', ['address' => $address, 'result' => $address !== '' ? $this->trace($address) : null]);
    }

    public function json(Request $request): JsonResponse
    {
        $address = strtolower(trim((string) $request->validate(['address' => ['required', 'string', 'max:200']])['address']));

        return response()->json($this->trace($address));
    }

    private function trace(string $address): array
    {
        $out = ['address' => $address, 'steps' => [], 'local' => false, 'valid' => (bool) filter_var($address, FILTER_VALIDATE_EMAIL)];
        $add = function (string $kind, string $title, string $text, ?string $href = null) use (&$out) {
            $out['steps'][] = compact('kind', 'title', 'text', 'href');
        };
        if (! $out['valid']) {
            $add('no', 'Адрес', 'это не похоже на почтовый адрес');

            return $out;
        }
        [$local, $domain] = explode('@', $address, 2);
        $ourDomains = Domain::query()->pluck('domain')->map('strtolower')->all();
        $out['local'] = in_array($domain, $ourDomains, true);

        if (! $out['local']) {
            // Чужой адрес: куда мы будем слать и как примем письма от него
            $mx = @dns_get_record($domain, DNS_MX) ?: [];
            usort($mx, fn ($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
            $add($mx ? 'ok' : 'warn', 'Домен ' . $domain, $mx ? 'наши письма уйдут на ' . implode(', ', array_map(fn ($r) => $r['target'] . ' (' . $r['pri'] . ')', array_slice($mx, 0, 3))) : 'у домена нет MX-записи — письма туда не доставятся');
            $this->senderRules($address, $domain, $add);

            return $out;
        }

        // ── Наш домен ──
        $mailbox = Mailbox::query()->where('username', $address)->first();
        $profile = $mailbox ? EmployeeProfile::query()->where('username', $address)->first() : null;
        $aliasRows = Forwarding::query()->where('address', $address)->get();
        $aliasTargets = $aliasRows->where('forwarding', '!=', $address)->pluck('forwarding')->map('strtolower')->unique()->values()->all();
        $isAlias = $aliasRows->where('is_alias', 1)->count() > 0 && ! $mailbox;
        $isList = $aliasRows->where('is_list', 1)->count() > 0;

        if ($mailbox) {
            $used = DB::connection('vmail')->table('used_quota')->where('username', $address)->value('bytes');
            $state = ! $mailbox->active ? 'ящик выключен — письма отклоняются' : (($profile?->login_blocked) ? 'вход закрыт, почта принимается и копится' : 'активен');
            $add(! $mailbox->active ? 'no' : ($profile?->login_blocked ? 'warn' : 'ok'), 'Ящик ' . ($mailbox->name ?: $address), $state . ($mailbox->quota ? ' · занято ' . round(($used ?? 0) / 1048576) . ' из ' . $mailbox->quota . ' МБ' : '') . ($profile?->is_service ? ' · служебный' : ''), '/mailboxes/' . rawurlencode($address) . '/edit');
        } elseif ($isAlias) {
            $add('ok', 'Псевдоним', 'ящика с таким адресом нет, письма уходят на: ' . implode(', ', $aliasTargets), '/aliases');
        } elseif ($isList) {
            $add('ok', 'Список рассылки', 'участники: ' . implode(', ', $aliasTargets), '/maillists');
        } else {
            $add('no', 'Адрес не существует', 'ни ящика, ни псевдонима — сервер ответит отправителю «User unknown»');
        }
        // Пересылка с ящика
        $fwd = $mailbox ? array_values(array_diff($aliasTargets, [$address])) : [];
        if ($mailbox && $fwd) {
            $keep = $aliasRows->where('forwarding', $address)->count() > 0;
            $add('warn', 'Пересылка', 'копия уходит на ' . implode(', ', $fwd) . ($keep ? ', копия в ящике остаётся' : ', в ящике НЕ остаётся'), '/mailboxes/' . rawurlencode($address) . '/edit');
        }
        // Кто пересылает сюда / чьи псевдонимы ведут сюда
        $incoming = Forwarding::query()->where('forwarding', $address)->where('address', '!=', $address)->get();
        if ($incoming->count()) {
            $al = $incoming->where('is_alias', 1)->pluck('address')->all();
            $fw = $incoming->where('is_alias', 0)->pluck('address')->all();
            if ($al) {
                $add('off', 'Дополнительные адреса', 'сюда же приходит почта на ' . implode(', ', $al), '/aliases');
            }
            if ($fw) {
                $add('off', 'Пересылают сюда', implode(', ', $fw));
            }
        }
        // Правила Sieve — читаем живой скрипт ящика, а не таблицу синхронизации
        if ($mailbox) {
            $rules = collect();
            try {
                $reader = app(\App\Services\Sieve\SieveScriptReader::class);
                $script = $reader->activeScript($mailbox);
                $rules = collect($script !== null ? $reader->parse($script) : []);
            } catch (\Throwable $e) {
                $add('warn', 'Правила Sieve', 'не удалось прочитать: ' . mb_substr($e->getMessage(), 0, 100));
            }
            foreach ($rules as $r) {
                $active = $r['active'] ?? true;
                $add($active ? 'ok' : 'off', (($r['kind'] ?? '') === 'vacation' ? 'Автоответ' : 'Правило'), trim((! empty($r['condition']) ? $r['condition'] . ' → ' : '') . ($r['action'] ?? '')) . ($active ? '' : ' (выключено)'), '/rules');
            }
            if ($rules->isEmpty()) {
                $add('off', 'Правила Sieve', 'личных правил нет — письмо ляжет во «Входящие», если его не заберут общие правила');
            }
            // Общий доступ
            $shares = collect((new FolderShares())->list($address, 'INBOX'));
            if ($shares->count()) {
                $add('off', 'Ящик открыт коллегам', $shares->map(fn ($s) => $s['name'] . ' (' . (FolderShares::TITLES[$s['level']] ?? $s['level']) . ')')->implode(', '), '/shares');
            }
            $has = DB::connection('vmail')->table('share_folder')->where('to_user', $address)->pluck('from_user')->all();
            if ($has) {
                $add('off', 'Видит чужие ящики', implode(', ', $has), '/shares');
            }
            // Отправка с чужих серверов
            $ext = ExternalSender::query()->where('address', $address)->first();
            if ($ext) {
                $add('off', 'Отправка с чужих серверов', 'разрешена через ' . $ext->provider, '/settings/domains');
            }
        }
        $this->senderRules($address, $domain, $add);

        return $out;
    }

    /** Общие правила и белый список, которые сработают на письма ОТ этого адреса. */
    private function senderRules(string $address, string $domain, callable $add): void
    {
        $rules = SenderRule::query()->where(function ($q) use ($address, $domain) {
            $q->where('value', $address)->orWhere('value', $domain);
        })->get();
        foreach ($rules as $r) {
            $what = ['spam' => 'письма от него уходят в «Спам»', 'lists' => 'письма от него уходят в «Рассылки»', 'ham' => 'в белом списке'][$r->kind] ?? $r->kind;
            $add($r->kind === 'spam' ? 'warn' : 'off', 'Общее правило по ' . ($r->match === 'domain' ? 'домену' : 'адресу') . ' ' . $r->value, $what . ' · голосов ' . $r->votes, '/rules');
        }
        try {
            foreach (app(WbList::class)->all() as $w) {
                $v = ltrim($w['email'], '@');
                if ($v === $address || $v === $domain) {
                    $add($w['wb'] === 'W' ? 'ok' : 'warn', ($w['wb'] === 'W' ? 'Белый' : 'Чёрный') . ' список Amavis: ' . $w['email'], $w['wb'] === 'W' ? 'антиспам письма от него не оценивает' : 'письма от него отбрасываются', '/settings/spam');
                }
            }
        } catch (\Throwable) {
        }
    }
}
