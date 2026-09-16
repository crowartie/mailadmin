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

        return response()->json(['rules' => $set?->rules ?? [], 'autoreply' => $set?->autoreply, 'script' => $set?->script]);
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
            'rules.*.conditions' => ['nullable', 'array', 'max:10'],
            'rules.*.conditions.*.field' => ['required', 'in:from,to,recipient,subject,body,header,size'],
            'rules.*.conditions.*.header' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9-]+$/'],
            'rules.*.conditions.*.op' => ['required', 'in:contains,not_contains,is,starts,ends,matches,over,under'],
            'rules.*.conditions.*.value' => ['nullable', 'string', 'max:500'],
            'rules.*.actions' => ['required', 'array', 'min:1', 'max:6'],
            'rules.*.actions.*.type' => ['required', 'in:move,copy,label,flag,seen,forward,forward_copy,discard,reply,stop'],
            'rules.*.actions.*.value' => ['nullable', 'string', 'max:2000'],
            'autoreply' => ['nullable', 'array'],
            'autoreply.enabled' => ['nullable', 'boolean'],
            'autoreply.from' => ['nullable', 'date_format:Y-m-d'],
            'autoreply.to' => ['nullable', 'date_format:Y-m-d'],
            'autoreply.subject' => ['nullable', 'string', 'max:200'],
            'autoreply.body' => ['nullable', 'string', 'max:5000'],
            'autoreply.days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $labels = Label::where('user', $imap->user())->pluck('name', 'id')->all();
        $script = $builder->build($data['rules'], $data['autoreply'] ?? null, $labels);

        try {
            $sieve = ManageSieveClient::forUser($imap->loginName(), $imap->password());
            $sieve->putScript(SieveBuilder::SCRIPT, $script);
            $sieve->setActive(SieveBuilder::SCRIPT);
            $sieve->logout();
        } catch (\RuntimeException $e) {
            abort(422, 'Сервер не принял правила: ' . $e->getMessage());
        }

        RuleSet::updateOrCreate(['user' => $imap->user()], [
            'rules' => $data['rules'], 'autoreply' => $data['autoreply'] ?? null, 'script' => $script,
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
