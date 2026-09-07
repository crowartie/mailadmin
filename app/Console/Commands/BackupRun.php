<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Server\BackupService;
use App\Services\Server\Quarantine;
use Illuminate\Console\Command;

/** Ночные задачи: резервная копия по расписанию из настроек и чистка карантина. */
class BackupRun extends Command
{
    protected $signature = 'backup:run {--if-due : только если сейчас время из настроек} {--purge-quarantine : почистить карантин по сроку}';

    protected $description = 'Резервная копия почтового сервера';

    public function handle(BackupService $backups, Quarantine $quarantine): int
    {
        if ($this->option('purge-quarantine')) {
            $n = $quarantine->purge((int) (AppSetting::group('quarantine')['keep_days'] ?? 14));
            $this->info("Карантин: удалено {$n}");

            return self::SUCCESS;
        }
        if ($this->option('if-due') && now()->format('H:i') !== (AppSetting::group('backup')['time'] ?? '03:00')) {
            return self::SUCCESS;
        }
        $b = $backups->run();
        $this->info($b->status === 'ok' ? "Копия готова: {$b->file}, " . round($b->size / 1048576) . ' МБ' : 'Ошибка: ' . $b->error);

        return $b->status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
