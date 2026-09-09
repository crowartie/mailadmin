<?php

namespace App\Services\Server;

/**
 * Отчёты сервера в виде текстовых файлов: Logwatch, скрипты iRedMail из cron, сводки и уведомления приложения.
 * Cron-скрипты складывает /usr/local/sbin/mailadmin-report в /var/lib/mailadmin/reports (владелец root, читает www-data),
 * приложение пишет свои в storage/app/private/reports. Раньше всё это уходило письмами на postmaster.
 */
class SystemReports
{
    public const KINDS = [
        'digest' => 'Сводка почты за сутки',
        'alerts' => 'Уведомления администратору',
        'logwatch' => 'Logwatch — сводка по журналам',
        'backup-mysql' => 'Резервная копия баз (скрипт iRedMail)',
        'iredadmin-cleanup' => 'Чистка удалённых ящиков (iRedAdmin)',
    ];

    public const SYSTEM_DIR = '/var/lib/mailadmin/reports';

    private const FILE_RE = '/^\d{4}-\d{2}-\d{2}_\d{4}(-\d+)?\.txt$/';

    public static function appDir(): string
    {
        return storage_path('app/private/reports');
    }

    public static function title(string $kind): string
    {
        return self::KINDS[$kind] ?? $kind;
    }

    /** @return array<int,array{kind:string,title:string,file:string,date:string,size:int,system:bool}> новые сверху */
    public function list(?string $kind = null): array
    {
        $out = [];
        foreach ([self::SYSTEM_DIR => true, self::appDir() => false] as $root => $system) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (scandir($root) ?: [] as $k) {
                if ($k[0] === '.' || ! preg_match('/^[a-z0-9-]{1,40}$/', $k) || ($kind && $k !== $kind) || ! is_dir("$root/$k")) {
                    continue;
                }
                foreach (scandir("$root/$k") ?: [] as $f) {
                    if (! preg_match(self::FILE_RE, $f) || ! is_readable("$root/$k/$f")) {
                        continue;
                    }
                    $out[] = [
                        'kind' => $k, 'title' => self::title($k), 'file' => $f,
                        'date' => substr($f, 0, 10) . ' ' . substr($f, 11, 2) . ':' . substr($f, 13, 2),
                        'size' => (int) filesize("$root/$k/$f"), 'system' => $system,
                    ];
                }
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['file'], $a['file']) ?: strcmp($a['kind'], $b['kind']));

        return $out;
    }

    /** @return array<int,array{kind:string,title:string,count:int}> */
    public function kinds(): array
    {
        $counts = [];
        foreach ($this->list() as $r) {
            $counts[$r['kind']] = ($counts[$r['kind']] ?? 0) + 1;
        }
        $out = [];
        foreach (self::KINDS as $k => $t) {
            $out[] = ['kind' => $k, 'title' => $t, 'count' => $counts[$k] ?? 0];
            unset($counts[$k]);
        }
        foreach ($counts as $k => $n) {
            $out[] = ['kind' => $k, 'title' => $k, 'count' => $n];
        }

        return $out;
    }

    public function path(string $kind, string $file): ?string
    {
        if (! preg_match('/^[a-z0-9-]{1,40}$/', $kind) || ! preg_match(self::FILE_RE, $file)) {
            return null;
        }
        foreach ([self::SYSTEM_DIR, self::appDir()] as $root) {
            if (is_file("$root/$kind/$file")) {
                return "$root/$kind/$file";
            }
        }

        return null;
    }

    public function read(string $kind, string $file): ?string
    {
        $p = $this->path($kind, $file);

        return $p ? (string) file_get_contents($p) : null;
    }

    /** Сохранить отчёт приложения (сводка, уведомление). @return string имя файла */
    public function save(string $kind, string $text): string
    {
        $dir = self::appDir() . '/' . $kind;
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $base = date('Y-m-d_Hi');
        $file = $base . '.txt';
        for ($n = 1; file_exists("$dir/$file"); $n++) {
            $file = $base . '-' . $n . '.txt';
        }
        file_put_contents("$dir/$file", '# ' . date('Y-m-d H:i:s') . " · " . self::title($kind) . "\n\n" . rtrim($text) . "\n");
        // Чистка: старше 180 дней.
        foreach (glob("$dir/*.txt") ?: [] as $old) {
            if (filemtime($old) < time() - 180 * 86400) {
                @unlink($old);
            }
        }

        return $file;
    }

    public function delete(string $kind, string $file): void
    {
        $p = $this->path($kind, $file);
        abort_if(! $p, 404, 'Отчёт не найден');
        if (str_starts_with($p, self::appDir())) {
            @unlink($p);

            return;
        }
        Ctl::out('report-delete', [$p]);
    }
}
