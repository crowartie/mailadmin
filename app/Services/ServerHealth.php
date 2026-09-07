<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * Состояние почтовых служб. На боевом сервере спрашивает systemd,
 * на машине без него честно говорит «нет данных» — ничего не выдумывает.
 */
class ServerHealth
{
    /** @var array<string,string> юнит systemd => человеческое название */
    private const UNITS = [
        'postfix' => 'Postfix — приём и отправка',
        'dovecot' => 'Dovecot — IMAP и POP3',
        'amavis' => 'Amavis — антиспам и антивирус',
        'clamav-daemon' => 'ClamAV — базы вирусов',
        'nginx' => 'nginx — веб',
        'iredapd' => 'iRedAPD — фильтр на входе',
    ];

    /**
     * @return array<int,array{name:string,status:string,kind:string}>
     */
    public function services(): array
    {
        return Cache::remember('health.services', 15, function () {
            $out = [];
            foreach (self::UNITS as $unit => $name) {
                $state = $this->unitState($unit);
                $out[] = [
                    'name' => $name,
                    'status' => match ($state) {
                        'active' => 'работает',
                        'disabled' => 'выключен',
                        'inactive', 'failed' => 'остановлен',
                        default => 'нет данных',
                    },
                    'kind' => match ($state) {
                        'active' => 'ok',
                        'inactive', 'failed' => 'no',
                        default => 'off',
                    },
                ];
            }

            return $out;
        });
    }

    /**
     * Строка в шапке: «Все службы работают» / «Остановлена: ClamAV» / «Нет данных».
     *
     * @return array{kind:string,text:string}
     */
    public function summary(): array
    {
        $services = $this->services();
        $known = array_filter($services, fn ($s) => $s['kind'] !== 'off');

        if (! $known) {
            return ['kind' => 'off', 'text' => 'Состояние служб недоступно'];
        }

        $down = array_values(array_filter($known, fn ($s) => $s['kind'] === 'no'));

        if (! $down) {
            return ['kind' => 'ok', 'text' => 'Все службы работают'];
        }

        $first = explode(' — ', $down[0]['name'])[0];

        return [
            'kind' => 'warn',
            'text' => count($down) === 1 ? "Остановлена: {$first}" : 'Остановлено служб: ' . count($down),
        ];
    }

    private function unitState(string $unit): string
    {
        if (! is_executable('/usr/bin/systemctl') && ! is_executable('/bin/systemctl')) {
            return 'unknown';
        }

        try {
            $process = new Process(['systemctl', 'is-active', $unit]);
            $process->setTimeout(3)->run();

            $state = trim($process->getOutput());
            if ($state === 'inactive') {
                // Выключенная намеренно служба (ClamAV без баз) — не авария.
                $en = new Process(['systemctl', 'is-enabled', $unit]);
                $en->setTimeout(3)->run();
                if (trim($en->getOutput()) === 'disabled') {
                    return 'disabled';
                }
            }

            // «unknown»/пустой ответ — юнита нет на этой машине.
            return in_array($state, ['active', 'inactive', 'failed', 'activating', 'disabled'], true) ? $state : 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
