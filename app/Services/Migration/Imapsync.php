<?php

namespace App\Services\Migration;

use App\Models\MailMigration;
use Symfony\Component\Process\Process;

/**
 * Перенос ящика со старого сервера (Kerio и любой IMAP) через imapsync.
 * Пароль сотрудника на старом сервере нужен, на нашем — не нужен: пишем через master-пользователя Dovecot.
 * Повторный запуск докачивает только новое (imapsync сверяет письма по заголовкам).
 */
class Imapsync
{
    public const BIN = '/usr/local/bin/imapsync';

    public static function dir(): string
    {
        $d = storage_path('app/private/migrations');
        if (! is_dir($d)) {
            mkdir($d, 0750, true);
        }

        return $d;
    }

    public static function available(): bool
    {
        return is_executable(self::BIN);
    }

    /** Проверить вход на обоих серверах. @return array{ok:bool,message:string} */
    public function test(MailMigration $m): array
    {
        [$code, $out] = $this->exec($m, ['--justlogin'], 60);
        $ok = $code === 0;
        $msg = $ok ? 'Вход на старый и новый сервер выполнен' : $this->explain($out);

        return ['ok' => $ok, 'message' => $msg];
    }

    /** Запустить перенос в фоне: artisan migrate:run <id>. */
    public function start(MailMigration $m, string $what = 'mail'): void
    {
        $m->update(['status' => 'queued', 'error' => null, 'what' => $what, 'started_at' => now(), 'finished_at' => null]);
        $p = Process::fromShellCommandline('nohup php artisan migrate:run ' . (int) $m->id . ' > /dev/null 2>&1 &', base_path(), ['HOME' => '/tmp']);
        $p->disableOutput();
        $p->run();
    }

    /** Разобрать очередь (status=queued) по одному ящику в фоне. */
    public function startQueue(): void
    {
        $p = Process::fromShellCommandline('nohup php artisan migrate:run --next > /dev/null 2>&1 &', base_path(), ['HOME' => '/tmp']);
        $p->disableOutput();
        $p->run();
    }

    /** Синхронно (вызывается командой): imapsync с журналом в файл, итог — в stats. */
    public function runMail(MailMigration $m): void
    {
        $log = self::dir() . '/' . $m->id . '.log';
        @unlink($log);
        $m->update(['status' => 'running', 'log_path' => $log]);
        // Имена папок в IMAP приходят в UTF-7, поэтому русские названия не трогаем: --automap сам сопоставляет
        // Sent Items/Отправленные/Junk E-mail и т. п. по SPECIAL-USE и известным именам.
        $extra = [
            '--automap', '--syncinternaldates', '--useheader', 'Message-Id', '--useheader', 'Message-ID', '--skipcrossduplicates',
            '--nofoldersizesatend', '--noreleasecheck', '--noerrorsdump',
            '--exclude', '^Public|^Shared|^#',
            '--regextrans2', 's/^Sent Items$/Sent/', '--regextrans2', 's/^Sent Messages$/Sent/', '--regextrans2', 's/^Deleted Items$/Trash/', '--regextrans2', 's/^Deleted Messages$/Trash/', '--regextrans2', 's/^Junk E-mail$/Junk/', '--regextrans2', 's/^Spam$/Junk/',
            '--subscribeall',
        ];
        if ($m->options['delete2'] ?? false) {
            $extra[] = '--delete2';
        }
        [$code, $out] = $this->exec($m, $extra, 6 * 3600, $log);
        $stats = self::parse($out);
        $m->update([
            'status' => $code === 0 ? 'done' : 'failed',
            'stats' => $stats,
            'error' => $code === 0 ? null : $this->explain($out),
            'finished_at' => now(),
        ]);
    }

    /** @return array{0:int,1:string} */
    private function exec(MailMigration $m, array $extra, int $timeout, ?string $logFile = null): array
    {
        $dir = self::dir();
        $p1 = $dir . '/' . $m->id . '.p1';
        $p2 = $dir . '/' . $m->id . '.p2';
        file_put_contents($p1, (string) $m->source_password);
        file_put_contents($p2, (string) config('areas.imap.master_password'));
        chmod($p1, 0600);
        chmod($p2, 0600);
        $args = array_merge([self::BIN,
            '--host1', $m->source_host, '--port1', (string) $m->source_port, '--user1', $m->source_login, '--passfile1', $p1,
            '--host2', '127.0.0.1', '--port2', '993', '--ssl2', '--user2', $m->target . '*' . config('areas.imap.master_user'), '--passfile2', $p2,
            '--sslargs1', 'SSL_verify_mode=0', '--sslargs2', 'SSL_verify_mode=0', '--timeout1', '120', '--timeout2', '120', '--nolog',
        ], $m->source_ssl ? ['--ssl1'] : ['--tls1'], $extra);
        $proc = new Process($args, base_path(), ['HOME' => '/tmp', 'LC_ALL' => 'C.UTF-8']);
        $proc->setTimeout($timeout);
        $buf = '';
        try {
            $proc->run(function ($type, $chunk) use (&$buf, $logFile) {
                $buf .= $chunk;
                if ($logFile) {
                    file_put_contents($logFile, $chunk, FILE_APPEND);
                }
            });
            $code = $proc->getExitCode() ?? 1;
        } catch (\Throwable $e) {
            $buf .= "\n" . $e->getMessage();
            $code = 1;
        } finally {
            @unlink($p1);
            @unlink($p2);
        }

        return [$code, $buf];
    }

    /** @return array<string,int|string> */
    public static function parse(string $out): array
    {
        $g = fn (string $re) => preg_match($re, $out, $mm) ? (int) $mm[1] : 0;

        return [
            'transferred' => $g('/Messages transferred\s*:\s*(\d+)/'),
            'skipped' => $g('/Messages skipped\s*:\s*(\d+)/'),
            'total_source' => $g('/Messages found in host1 not in host2\s*:\s*(\d+)/') + $g('/Messages transferred\s*:\s*(\d+)/'),
            'folders' => $g('/Folders synced\s*:\s*(\d+)/'),
            'bytes' => $g('/Total bytes transferred\s*:\s*(\d+)/'),
            'errors' => $g('/Detected (\d+) errors/'),
            'seconds' => $g('/Transfer time\s*:\s*([\d.]+)/'),
        ];
    }

    /** Живой прогресс из журнала (для страницы). */
    public static function progress(MailMigration $m): array
    {
        $log = $m->log_path;
        $tail = '';
        $done = 0;
        $total = 0;
        $folder = '';
        if ($log && is_file($log)) {
            $fh = fopen($log, 'rb');
            while (($line = fgets($fh)) !== false) {
                if (str_contains($line, ' copied to ')) {
                    $done++;
                } elseif (! $total && preg_match('/Host1 Nb messages:\s+(\d+)/', $line, $mm)) {
                    $total = (int) $mm[1];
                }
            }
            $size = ftell($fh);
            fseek($fh, max(0, $size - 20000));
            $tail = (string) stream_get_contents($fh);
            fclose($fh);
            if (preg_match_all('/msg (\S+)\/\d+ \{\d+\}/', $tail, $mm)) {
                $folder = end($mm[1]);
            }
        }
        $lines = array_slice(array_values(array_filter(preg_split('/\r?\n/', $tail))), -6);

        return ['folder' => $folder, 'done' => $done, 'total' => $total, 'tail' => implode("\n", $lines)];
    }

    private function explain(string $out): string
    {
        if (preg_match('/Exiting with return value (\d+) \((EXIT_[A-Z0-9_]+)\)/', $out, $m) && (int) $m[1] !== 0) {
            $name = $m[2];
            $side = str_ends_with($name, '1') ? 'Старый сервер' : (str_ends_with($name, '2') ? 'Наш сервер' : '');
            if (str_contains($name, 'CONNECTION_FAILURE')) {
                return $side . ' не отвечает: проверьте адрес, порт и SSL';
            }
            if (str_contains($name, 'TLS_FAILURE')) {
                return $side . ': не удалось установить TLS — попробуйте другой порт или выключить SSL';
            }
            if (str_contains($name, 'AUTHENTICATION_FAILURE')) {
                return $side . ' не принял логин/пароль';
            }
            if (str_contains($name, 'OVERQUOTA')) {
                return 'Наш ящик переполнен — увеличьте квоту и запустите ещё раз';
            }
            if (str_contains($name, 'WITH_ERRORS')) {
                $n = preg_match('/Detected (\d+) errors/', $out, $mm) ? (int) $mm[1] : 0;
                $last = preg_match_all('/^Err \d+\/\d+: (.+)$/m', $out, $mm) ? end($mm[1]) : '';

                return 'Перенос прошёл с ошибками' . ($n ? " ({$n})" : '') . ($last ? ': ' . mb_substr($last, 0, 200) : '') . ' — запустите ещё раз, докачается недостающее';
            }
            if (str_contains($name, 'BY_SIGNAL')) {
                return 'Перенос прерван';
            }

            return 'imapsync: ' . $name;
        }
        if (preg_match('/failure: Error login on .*?user \[[^\]]*\]/i', $out, $m)) {
            return 'Сервер не принял логин/пароль';
        }
        $lines = array_values(array_filter(preg_split('/?
/', $out), fn ($l) => preg_match('/error|fail|refused|denied|timeout/i', $l)));

        return $lines ? mb_substr(end($lines), 0, 300) : mb_substr(trim($out), -300);
    }
}
