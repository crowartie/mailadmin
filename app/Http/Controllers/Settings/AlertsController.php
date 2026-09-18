<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\AppSetting;
use App\Services\Server\Alerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Уведомления администраторам: куда писать и проверка, что канал работает. */
class AlertsController extends Controller
{
    public function __construct(
        private readonly Alerts $alerts,
    ) {
    }

    public function saveAlerts(Request $request): RedirectResponse
    {
        $a = $request->validate([
            'queue' => ['boolean'], 'queue_size' => ['required', 'integer', 'min:1', 'max:100000'], 'queue_age_hours' => ['required', 'integer', 'min:1', 'max:240'],
            'disk' => ['boolean'], 'disk_pct' => ['required', 'integer', 'min:50', 'max:99'], 'services' => ['boolean'], 'backup' => ['boolean'], 'admin_login' => ['boolean'], 'feedback' => ['boolean'],
            'digest' => ['boolean'], 'digest_time' => ['required', 'date_format:H:i'],
            'emails' => ['nullable', 'string', 'max:500'], 'telegram_token' => ['nullable', 'string', 'max:100'], 'telegram_chat' => ['nullable', 'string', 'max:40'], 'telegram_proxy' => ['nullable', 'string', 'max:200'],
        ]);
        $channels = array_intersect_key($a, array_flip(['emails', 'telegram_token', 'telegram_chat', 'telegram_proxy']));
        AppSetting::put('alerts', array_diff_key($a, $channels) + ['queue' => false, 'disk' => false, 'services' => false, 'backup' => false, 'admin_login' => false, 'digest' => false, 'feedback' => false]);
        AppSetting::put('channels', array_map(fn ($v) => trim((string) $v), $channels));
        AdminAction::log('settings.update', 'уведомления');

        return back()->with('success', 'Уведомления сохранены');
    }

    public function testAlerts(): RedirectResponse
    {
        $ch = AppSetting::group('channels');
        $text = '✅ Проверка уведомлений почтового сервера ' . config('areas.default_domain') . ' — ' . now()->format('d.m.Y H:i');
        $tg = $this->alerts->telegram($text);
        $this->alerts->send($text);
        $parts = [];
        if (filled($ch['emails'])) {
            $parts[] = 'письмо на ' . $ch['emails'];
        }
        $parts[] = filled($ch['telegram_token']) ? ($tg ? 'Telegram доставлен' : 'Telegram не ответил — проверьте токен, chat_id и прокси') : 'Telegram не настроен';

        return back()->with($tg || filled($ch['emails']) ? 'success' : 'error', 'Отправлено: ' . implode('; ', $parts));
    }
}
