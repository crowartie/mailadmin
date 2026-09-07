<?php

namespace App\Services\Server;

use Symfony\Component\Process\Process;

/**
 * Единственная дверь к серверу: `sudo mailadmin-ctl <подкоманда> …`.
 * Белый список подкоманд и проверка аргументов живут в самом скрипте (deploy/mailadmin-ctl),
 * здесь — только запуск, тайм-аут и разбор вывода.
 */
class Ctl
{
    public const BIN = '/usr/local/sbin/mailadmin-ctl';

    /** @return array{0:int,1:string,2:string} код, stdout, stderr */
    public static function run(string $command, array $args = [], int $timeout = 30): array
    {
        if (! is_executable(self::BIN)) {
            return [127, '', 'mailadmin-ctl не установлен'];
        }
        $process = new Process(array_merge(['sudo', '-n', self::BIN, $command], array_map('strval', $args)));
        $process->setTimeout($timeout);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return [1, '', $e->getMessage()];
        }

        return [$process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput()];
    }

    /** stdout или исключение с текстом stderr. */
    public static function out(string $command, array $args = [], int $timeout = 30): string
    {
        [$code, $out, $err] = self::run($command, $args, $timeout);
        if ($code !== 0) {
            throw new \RuntimeException(trim($err) !== '' ? trim($err) : "команда {$command} завершилась с кодом {$code}");
        }

        return $out;
    }

    public static function available(): bool
    {
        return is_executable(self::BIN);
    }
}
