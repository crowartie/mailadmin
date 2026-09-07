<?php

namespace App\Services\Server;

use App\Models\AppSetting;
use App\Models\Backup;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/** Резервные копии: запуск mailadmin-backup, журнал, файлы на диске, восстановление ящика. */
class BackupService
{
    /** Запустить копию сейчас (синхронно; на большой почте — минуты). @return Backup */
    public function run(): Backup
    {
        $s = AppSetting::group('backup');
        $parts = implode(',', array_keys(array_filter(['mail' => $s['mail'], 'db' => $s['db'], 'config' => $s['config']])));
        Cache::put('backup.running', time(), 3600);
        [$code, $out, $err] = Ctl::run('backup-run', ['run', $s['dir'], (string) $s['keep_daily'], (string) $s['keep_weekly'], $parts ?: 'db,config'], 3600);
        $last = @json_decode((string) @file_get_contents(rtrim($s['dir'], '/') . '/last.json'), true) ?: [];
        $ok = $code === 0 && ($last['status'] ?? '') === 'ok';
        Cache::forget('backup.running');

        return Backup::create([
            'file' => $last['file'] ?? null,
            'size' => (int) ($last['size'] ?? 0),
            'seconds' => (int) ($last['seconds'] ?? 0),
            'parts' => $parts ?: 'db,config',
            'status' => $ok ? 'ok' : 'failed',
            'error' => $ok ? null : (trim(($last['error'] ?? '') . ' ' . $err) ?: 'см. backup.log'),
            'created_at' => now(),
        ]);
    }

    /** Запустить в фоне: artisan backup:run отдельным процессом, страница не ждёт. */
    public function runInBackground(): void
    {
        Cache::put('backup.running', time(), 3600);
        $p = Process::fromShellCommandline('nohup php artisan backup:run > /dev/null 2>&1 &', base_path(), ['HOME' => '/tmp']);
        $p->disableOutput();
        $p->run();
    }

    /** @return array<int,array{file:string,size:int,mtime:int}> */
    public function files(): array
    {
        $dir = AppSetting::group('backup')['dir'];
        [$code, $out] = Ctl::run('backup-list', [$dir], 10);
        if ($code !== 0) {
            return [];
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            if (preg_match('/^(\d+) (\d+) (mail-.*\.tar\.gz)$/', trim($line), $m)) {
                $rows[] = ['file' => rtrim($dir, '/') . '/' . $m[3], 'size' => (int) $m[2], 'mtime' => (int) $m[1]];
            }
        }
        usort($rows, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        return $rows;
    }

    /** @return array{ok:bool,free:?int,message:string} */
    public function checkDir(): array
    {
        $dir = AppSetting::group('backup')['dir'];
        if (! is_dir($dir)) {
            return ['ok' => false, 'free' => null, 'message' => 'Каталог не существует или не смонтирован'];
        }
        $free = @disk_free_space($dir);

        return ['ok' => true, 'free' => $free !== false ? (int) $free : null, 'message' => $free !== false ? 'свободно ' . round($free / 1073741824) . ' ГБ' : 'доступен'];
    }

    public function restoreMailbox(string $file, string $user): string
    {
        return trim(Ctl::out('backup-restore-mailbox', [$file, $user], 1800));
    }
}
