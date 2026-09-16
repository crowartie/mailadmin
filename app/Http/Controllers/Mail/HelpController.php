<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Webmail\Setting;
use App\Services\Server\AmavisConfig;
use App\Services\Server\Throttle;
use App\Support\Area;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Справочник сотрудника по веб-почте. Открыт и без входа (чтобы прочитать «как войти»),
 * цифры и адреса подставляются из настроек сервера, чтобы текст не расходился с реальностью.
 */
class HelpController extends Controller
{
    public function index(Request $request): Response
    {
        $user = (string) $request->session()->get('mail.user', '');
        $domain = (string) config('areas.default_domain');
        $base = Area::mailBase();
        $limits = AppSetting::group('limits');
        $cloud = AppSetting::group('cloud');
        $quarantine = AppSetting::group('quarantine');
        $senders = AppSetting::group('senders');
        $security = AppSetting::group('security');
        $throttle = $this->safe(fn () => Throttle::get(), ['max_msgs' => 0, 'period_min' => 60, 'enabled' => false]);
        $sizeLimitMb = $this->safe(fn () => app(AmavisConfig::class)->current()['sizeLimitMb'], 15);

        return Inertia::render('Mail/Help', [
            'user' => $user,
            'settings' => $user !== '' ? Setting::for($user) : ['theme' => 'system'],
            'domain' => $domain,
            'base' => $base,
            'hosts' => ['imap' => 'imap.' . $domain, 'smtp' => 'smtp.' . $domain, 'dav' => $base . '/dav/', 'mobileconfig' => $base . '/mail/apple.mobileconfig' . ($user !== '' ? '?email=' . rawurlencode($user) : '')],
            'cloud' => ['enabled' => (bool) ($cloud['enabled'] ?? false), 'thresholdMb' => (int) ($cloud['threshold_mb'] ?? 10), 'expireDays' => (int) ($cloud['expire_days'] ?? 30)],
            'quarantine' => ['digest' => (bool) ($quarantine['digest'] ?? true), 'digestTime' => (string) ($quarantine['digest_time'] ?? '09:00'), 'keepDays' => (int) ($quarantine['keep_days'] ?? 14)],
            'senders' => ['spamVotes' => (int) ($senders['spam_votes'] ?? 2), 'listsVotes' => (int) ($senders['lists_votes'] ?? 2), 'hamGlobal' => (bool) ($senders['ham_global'] ?? true)],
            'security' => ['appPasswords' => (bool) ($security['app_passwords'] ?? true), 'newDevice' => (bool) ($security['notify_new_device'] ?? true)],
            'limits' => [
                'quotaMb' => (int) ($limits['default_quota_mb'] ?? 0),
                'sizeLimitMb' => (int) $sizeLimitMb,
                'blockedExt' => (string) ($limits['blocked_ext'] ?? ''),
                'maxRecipients' => (int) ($limits['max_recipients'] ?? 100),
                'throttle' => ['enabled' => (bool) ($throttle['enabled'] ?? false), 'maxMsgs' => (int) ($throttle['max_msgs'] ?? 0), 'periodMin' => (int) ($throttle['period_min'] ?? 60)],
            ],
        ]);
    }

    private function safe(callable $fn, mixed $default = null): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $default;
        }
    }
}
