<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\AppSetting;
use App\Services\Server\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Резервные копии: расписание, запуск вручную, восстановление ящика из архива.
 *
 * Ночной архив всей почты выключен намеренно — он занимал 123 ГБ на том же диске,
 * где лежит сама почта.
 */
class BackupController extends Controller
{
    public function __construct(
        private readonly BackupService $backups,
    ) {
    }

    public function saveBackup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dir' => ['required', 'string', 'regex:#^/[A-Za-z0-9/_.-]+$#'], 'time' => ['required', 'date_format:H:i'],
            'keep_daily' => ['required', 'integer', 'min:1', 'max:365'], 'keep_weekly' => ['required', 'integer', 'min:0', 'max:104'],
            'mail' => ['boolean'], 'db' => ['boolean'], 'config' => ['boolean'], 'vm_snapshot' => ['boolean'],
        ]);
        AppSetting::put('backup', $data + ['mail' => false, 'db' => false, 'config' => false, 'vm_snapshot' => false]);
        AdminAction::log('settings.update', 'резервные копии', $data['dir'] . ' в ' . $data['time']);

        return back()->with('success', 'Расписание копий сохранено');
    }

    public function runBackup(): RedirectResponse
    {
        if (Cache::get('backup.running')) {
            return back()->with('error', 'Копия уже выполняется');
        }
        $this->backups->runInBackground();
        AdminAction::log('backup.run');

        return back()->with('success', 'Копия запущена — итог появится в журнале ниже');
    }

    public function restoreBackup(Request $request): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'string', 'regex:#^/[A-Za-z0-9/_.-]+\.tar\.gz$#'], 'user' => ['required', 'email']]);
        try {
            $out = $this->backups->restoreMailbox($data['file'], strtolower($data['user']));
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Не восстановлено: ' . mb_substr($e->getMessage(), 0, 300));
        }
        AdminAction::log('backup.restore', $data['user'], basename($data['file']));

        return back()->with('success', 'Ящик ' . $data['user'] . ' восстановлен из ' . basename($data['file']) . ($out ? ' (' . mb_substr($out, 0, 120) . ')' : ''));
    }
}
