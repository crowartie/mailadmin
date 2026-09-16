<?php

namespace App\Console\Commands;

use App\Services\Mail\FolderShares;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Общий доступ к ящикам: доступ по «Входящим» — это доступ к ящику, но папки, созданные после выдачи
 * (автосозданная «Рассылки», «Архив», новая папка владельца), права сами не получают. Докладываем их.
 */
class SharesSync extends Command
{
    protected $signature = 'shares:sync {owner? : только этот ящик}';

    protected $description = 'Доложить права общего доступа на папки, появившиеся после выдачи';

    public function handle(FolderShares $shares): int
    {
        $owners = $this->argument('owner')
            ? [strtolower($this->argument('owner'))]
            : DB::connection('vmail')->table('share_folder')->distinct()->pluck('from_user')->map('strtolower')->all();
        $total = 0;
        foreach ($owners as $owner) {
            try {
                $done = $shares->sync($owner);
            } catch (\Throwable $e) {
                $this->warn("{$owner}: " . $e->getMessage());
                continue;
            }
            foreach ($done as $line) {
                $this->line("{$owner}: {$line}");
            }
            $total += count($done);
        }
        $this->info('доложено прав: ' . $total);
        \Illuminate\Support\Facades\Cache::put('heartbeat.shares_sync', time(), 86400);

        return self::SUCCESS;
    }
}
