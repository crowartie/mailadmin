<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Models\Webmail\Label;
use App\Models\Webmail\RuleSet;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use App\Services\Mail\ManageSieveClient;
use App\Services\Mail\RuleRunner;
use App\Services\Mail\SieveBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Правила и автоответ: хранятся в базе, применяются скриптом Sieve через ManageSieve. */
class RulesController extends Controller
{
    public function show(ImapSession $imap): JsonResponse
    {
        $set = RuleSet::find($imap->user());

        return response()->json(['rules' => $set?->rules ?? [], 'autoreply' => $set?->autoreply, 'script' => $set?->script, 'custom' => $set?->custom ?? '']);
    }

    /** Условия и действия — одни и те же у правила и у его уточнений. */
    private static function partRules(string $p): array
    {
        return [
            $p . 'conditions' => ['nullable', 'array', 'max:10'],
            $p . 'conditions.*.field' => ['required', 'in:from,to,recipient,subject,body,header,size'],
            $p . 'conditions.*.header' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9-]+$/'],
            $p . 'conditions.*.op' => ['required', 'in:contains,not_contains,is,starts,ends,matches,over,under'],
            $p . 'conditions.*.value' => ['nullable', 'string', 'max:500'],
            $p . 'actions' => ['required', 'array', 'min:1', 'max:6'],
            $p . 'actions.*.type' => ['required', 'in:move,copy,move_by_sender,move_by_domain,move_by_name,label,flag,seen,forward,forward_copy,discard,reply,stop'],
            $p . 'actions.*.value' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Проверить свой скрипт самим Dovecot (CHECKSCRIPT), не сохраняя: вместе с правилами конструктора,
     * как он и будет выполняться. Ошибка приходит со строкой — но строки считаются по общему скрипту,
     * поэтому отдаём и его.
     */
    public function check(Request $request, ImapSession $imap, SieveBuilder $builder): JsonResponse
    {
        $data = $request->validate(['custom' => ['nullable', 'string', 'max:20000']]);
        $set = RuleSet::find($imap->user());
        $labels = Label::where('user', $imap->user())->pluck('name', 'id')->all();
        $script = $builder->build($set?->rules ?? [], $set?->autoreply, $labels, $data['custom'] ?? '');
        try {
            $sieve = ManageSieveClient::forUser($imap->loginName(), $imap->password());
            $sieve->checkScript($script);
            $sieve->logout();
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'script' => $script]);
        }

        return response()->json(['ok' => true, 'script' => $script]);
    }

    public function update(Request $request, ImapSession $imap, SieveBuilder $builder): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['present', 'array', 'max:50'],
            'rules.*.id' => ['nullable'],
            'rules.*.name' => ['nullable', 'string', 'max:120'],
            'rules.*.enabled' => ['nullable', 'boolean'],
            'rules.*.match' => ['nullable', 'in:all,any'],
            'rules.*.stop' => ['nullable', 'boolean'],
            ...self::partRules('rules.*.'),
            // Уточнения внутри правила (см. SieveBuilder): до десяти, каждое — свои условия и действия.
            'rules.*.refine' => ['nullable', 'array', 'max:10'],
            'rules.*.refine.*.match' => ['nullable', 'in:all,any'],
            ...self::partRules('rules.*.refine.*.'),
            'custom' => ['nullable', 'string', 'max:20000'],
            'autoreply' => ['nullable', 'array'],
            'autoreply.enabled' => ['nullable', 'boolean'],
            'autoreply.from' => ['nullable', 'date_format:Y-m-d'],
            'autoreply.to' => ['nullable', 'date_format:Y-m-d'],
            'autoreply.subject' => ['nullable', 'string', 'max:200'],
            'autoreply.body' => ['nullable', 'string', 'max:5000'],
            'autoreply.days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $labels = Label::where('user', $imap->user())->pluck('name', 'id')->all();
        // Свой скрипт присылают только с его вкладки; иначе остаётся прежний.
        $custom = array_key_exists('custom', $data) ? (string) ($data['custom'] ?? '') : (string) (RuleSet::find($imap->user())?->custom ?? '');
        $script = $builder->build($data['rules'], $data['autoreply'] ?? null, $labels, $custom);

        try {
            $sieve = ManageSieveClient::forUser($imap->loginName(), $imap->password());
            $sieve->putScript(SieveBuilder::SCRIPT, $script);
            $sieve->setActive(SieveBuilder::SCRIPT);
            $sieve->logout();
        } catch (\RuntimeException $e) {
            abort(422, ($custom !== '' ? 'Сервер не принял скрипт (проверьте свой скрипт на вкладке «Свой скрипт»): ' : 'Сервер не принял правила: ') . $e->getMessage());
        }

        RuleSet::updateOrCreate(['user' => $imap->user()], [
            'rules' => $data['rules'], 'autoreply' => $data['autoreply'] ?? null, 'script' => $script, 'custom' => $custom,
        ]);

        return response()->json(['ok' => true, 'script' => $script]);
    }

    /** Прогнать сохранённые правила по уже лежащим во «Входящих» письмам. */
    public function apply(ImapSession $imap): JsonResponse
    {
        set_time_limit(600);
        $set = RuleSet::find($imap->user());
        $rules = $set?->rules ?? [];
        abort_if(! $rules, 422, 'Правил пока нет');
        $store = new MailStore($imap->client());
        $results = (new RuleRunner($store, $imap->user()))->run($rules);

        return response()->json(['ok' => true, 'results' => $results, 'folders' => $store->folders()]);
    }
}
