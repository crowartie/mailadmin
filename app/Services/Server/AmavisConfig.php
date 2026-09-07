<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * Антиспам: пороги SpamAssassin в Amavis, антивирус, серый список iRedAPD, лимиты Postfix.
 * Всё через mailadmin-ctl — правки в /etc/amavis/conf.d/50-user, /opt/iredapd/settings.py и postconf.
 */
class AmavisConfig
{
    /** @return array{tag:float,tag2:float,kill:float,cutoff:float,virus:bool,greylist:bool,sizeLimitMb:int} */
    public function current(): array
    {
        return Cache::remember('amavis.config', 30, function () {
            $get = function (string $key, float $default): float {
                [$code, $out] = Ctl::run('amavis-get', [$key], 10);

                return $code === 0 && preg_match('/=\s*([\d.]+)/', $out, $m) ? (float) $m[1] : $default;
            };
            [$code, $plugins] = Ctl::run('iredapd-plugins', [], 10);
            [$c2, $size] = Ctl::run('postconf-get', ['message_size_limit'], 10);
            // Антивирус: в 50-user он выключается массивом @bypass_virus_checks_maps, надёжнее смотреть на службу.
            $p = new Process(['systemctl', 'is-active', 'clamav-daemon']);
            $p->run();
            $clam = trim($p->getOutput());

            return [
                'tag' => $get('sa_tag_level_deflt', 2.0),
                'tag2' => $get('sa_tag2_level_deflt', 6.2),
                'kill' => $get('sa_kill_level_deflt', 6.9),
                'cutoff' => $get('sa_dsn_cutoff_level', 10),
                'virus' => $clam === 'active',
                'greylist' => $code === 0 && str_contains($plugins, '"greylisting"'),
                'sizeLimitMb' => $c2 === 0 ? (int) round(((int) trim($size)) / 1048576) : 15,
            ];
        });
    }

    /** @param array{tag2?:float,kill?:float,cutoff?:float} $levels */
    public function setLevels(array $levels): void
    {
        $map = ['tag2' => 'sa_tag2_level_deflt', 'kill' => 'sa_kill_level_deflt', 'cutoff' => 'sa_dsn_cutoff_level', 'tag' => 'sa_tag_level_deflt'];
        $changed = false;
        foreach ($levels as $k => $v) {
            if (isset($map[$k]) && $v !== null) {
                Ctl::out('amavis-set', [$map[$k], number_format((float) $v, 1, '.', '')], 60);
                $changed = true;
            }
        }
        $this->dirty = $this->dirty || $changed;
        Cache::forget('amavis.config');
    }

    private bool $dirty = false;

    /** Один перезапуск Amavis после всех правок (несколько подряд упираются в start-limit systemd). */
    public function apply(): void
    {
        if (! $this->dirty) {
            return;
        }
        $state = trim(Ctl::out('amavis-apply', [], 90));
        $this->dirty = false;
        Cache::forget('health.services');
        if ($state !== 'active') {
            throw new \RuntimeException('Amavis не поднялся после перезапуска (' . $state . ') — смотрите journalctl -u amavis');
        }
    }

    public function setVirus(bool $on): void
    {
        if ($on) {
            Ctl::out('service-enable', ['clamav-freshclam'], 60);
            Ctl::out('service-enable', ['clamav-daemon'], 60);
        } else {
            Ctl::out('service-disable', ['clamav-daemon'], 60);
            Ctl::out('service-disable', ['clamav-freshclam'], 60);
        }
        Ctl::out('amavis-virus', [$on ? 'on' : 'off'], 60);
        $this->dirty = true;
        Cache::forget('amavis.config');
        Cache::forget('health.services');
    }

    public function setGreylist(bool $on): void
    {
        Ctl::out('iredapd-greylist', [$on ? 'on' : 'off'], 60);
        Cache::forget('amavis.config');
    }

    public function setSizeLimit(int $mb): void
    {
        Ctl::out('postconf-set', ['message_size_limit', (string) ($mb * 1048576)], 30);
        Cache::forget('amavis.config');
    }
}
