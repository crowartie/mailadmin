<?php

namespace App\Services\Cloud;

/** Учёт в памяти — для тестов. Ведёт себя так же, как DbCloudLedger. */
final class ArrayCloudLedger implements CloudLedger
{
    public array $links = [];

    public array $uploads = [];

    public array $trash = [];

    private int $seq = 0;

    public function links(string $user): array
    {
        $out = [];
        foreach ($this->links[$user] ?? [] as $path => $l) {
            $out[$path] = $l;
        }

        return $out;
    }

    public function link(string $user, string $path): ?array
    {
        return $this->links[$user][$path] ?? null;
    }

    public function saveLink(string $user, array $link): void
    {
        $this->links[$user][$link['path']] = $link;
    }

    public function forgetLink(string $user, string $path): void
    {
        unset($this->links[$user][$path]);
    }

    public function linksUnder(string $user, string $path): array
    {
        return array_values(array_filter($this->links[$user] ?? [], fn ($l) => PersonalPath::within($l['path'], $path)));
    }

    public function saveUpload(array $upload): void
    {
        $this->uploads[$upload['id']] = $upload + ['finished_at' => null, 'created_at' => date('Y-m-d H:i:s')];
    }

    public function upload(string $user, string $id): ?array
    {
        $u = $this->uploads[$id] ?? null;

        return $u && $u['user'] === $user ? $u : null;
    }

    public function finishUpload(string $id, string $path): void
    {
        $this->uploads[$id]['path'] = $path;
        $this->uploads[$id]['finished_at'] = date('Y-m-d H:i:s');
    }

    public function forgetUpload(string $id): void
    {
        unset($this->uploads[$id]);
    }

    public function recentUploads(string $user, int $limit): array
    {
        $rows = array_values(array_filter($this->uploads, fn ($u) => $u['user'] === $user && $u['finished_at']));
        usort($rows, fn ($a, $b) => strcmp($b['finished_at'], $a['finished_at']));

        return array_slice($rows, 0, $limit);
    }

    public function trash(string $user): array
    {
        return array_values(array_filter($this->trash, fn ($t) => $t['user'] === $user));
    }

    public function trashItem(string $user, int $id): ?array
    {
        $t = $this->trash[$id] ?? null;

        return $t && $t['user'] === $user ? $t : null;
    }

    public function addTrash(array $row): int
    {
        $id = ++$this->seq;
        $this->trash[$id] = $row + ['id' => $id];

        return $id;
    }

    public function forgetTrash(int $id): void
    {
        unset($this->trash[$id]);
    }

    public function trashOlderThan(\DateTimeInterface $before): array
    {
        return array_values(array_filter($this->trash, fn ($t) => strtotime($t['deleted_at']) < $before->getTimestamp()));
    }

    public function movePrefix(string $user, string $from, string $to): void
    {
        $map = fn (string $p) => $p === $from ? $to : (str_starts_with($p, $from . '/') ? $to . substr($p, strlen($from)) : $p);
        $moved = [];
        foreach ($this->links[$user] ?? [] as $l) {
            $l['path'] = $map($l['path']);
            $moved[$l['path']] = $l;
        }
        $this->links[$user] = $moved;
        foreach ($this->uploads as $id => $u) {
            if ($u['user'] === $user) {
                $this->uploads[$id]['path'] = $map($u['path']);
            }
        }
    }
}
