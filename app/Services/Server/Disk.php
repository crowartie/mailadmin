<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\Cache;

/** Место на дисках: почта (/var/vmail) и система. */
class Disk
{
    /** @return array{size:int,used:int,avail:int,pct:int}|null */
    public function vmail(): ?array
    {
        return $this->stat('/var/vmail');
    }

    public function root(): ?array
    {
        return $this->stat('/');
    }

    private function stat(string $path): ?array
    {
        return Cache::remember('disk.' . md5($path), 60, function () use ($path) {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if (! $total) {
                return null;
            }

            return ['size' => (int) $total, 'used' => (int) ($total - $free), 'avail' => (int) $free, 'pct' => (int) round(($total - $free) / $total * 100)];
        });
    }
}
