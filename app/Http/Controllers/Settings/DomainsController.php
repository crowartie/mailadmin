<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\AppSetting;
use App\Models\Vmail\Domain;
use App\Services\Server\Ctl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Домены и записи DNS: перепроверка, почтовый узел домена, MTA-STS, отчёты DMARC
 * и смена ключа DKIM.
 *
 * Смена ключа — действие с последствиями: пока новая запись не разойдётся по DNS,
 * письма подписываются ключом, которого получатель ещё не видит.
 */
class DomainsController extends Controller
{
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
}
