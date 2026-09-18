<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\AppSetting;
use App\Services\Server\AmavisConfig;
use App\Services\Server\Ctl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Пределы: размер письма, вложения, ограничение частоты отправки, fail2ban. */
class LimitsController extends Controller
{
    public function __construct(
        private readonly AmavisConfig $amavis,
    ) {
    }

    public function saveLimits(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sizeLimitMb' => ['required', 'integer', 'min:1', 'max:1024'],
            'default_quota_mb' => ['required', 'integer', 'min:0', 'max:1000000'],
            'blocked_ext' => ['nullable', 'string', 'max:300'],
            'max_recipients' => ['required', 'integer', 'min:1', 'max:5000'],
            'maxretry' => ['required', 'integer', 'min:2', 'max:100'], 'findtime' => ['required', 'integer', 'min:1', 'max:1440'], 'bantime_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'out_max_msgs' => ['required', 'integer', 'min:0', 'max:100000'], 'out_period_min' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);
        try {
            \App\Services\Server\Throttle::set((int) $data['out_max_msgs'], (int) $data['out_period_min']);
        } catch (\Throwable $e) {
            return back()->with('error', 'Лимит исходящих не записан в iRedAPD: ' . mb_substr($e->getMessage(), 0, 160));
        }
        try {
            if ($data['sizeLimitMb'] !== $this->amavis->current()['sizeLimitMb']) {
                $this->amavis->setSizeLimit($data['sizeLimitMb']);
            }
            Ctl::out('postconf-set', ['smtpd_recipient_limit', (string) $data['max_recipients']], 30);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Postfix не принял значение: ' . $e->getMessage());
        }
        AppSetting::put('limits', ['default_quota_mb' => $data['default_quota_mb'], 'blocked_ext' => $data['blocked_ext'] ?? '', 'max_recipients' => $data['max_recipients']]);
        AppSetting::put('fail2ban', ['maxretry' => $data['maxretry'], 'findtime' => $data['findtime'], 'bantime_hours' => $data['bantime_hours']]);
        // Jail «mailadmin» (админка и веб-почта) перечитывает пороги сразу; штатные jail'ы iRedMail не трогаем.
        try {
            Ctl::out('f2b-config', [(string) $data['maxretry'], (string) $data['findtime'], (string) $data['bantime_hours']], 60);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Лимиты сохранены, но fail2ban не перечитал правила: ' . mb_substr($e->getMessage(), 0, 200));
        }
        AdminAction::log('settings.update', 'лимиты', 'письмо до ' . $data['sizeLimitMb'] . ' МБ');

        return back()->with('success', 'Лимиты сохранены');
    }
}
