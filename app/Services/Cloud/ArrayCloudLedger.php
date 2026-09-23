<?php

namespace App\Services\Cloud;

/** Учёт в памяти — для тестов. Ведёт себя так же, как DbCloudLedger. */
final class ArrayCloudLedger implements CloudLedger
{
    public array $links = [];

    public array $uploads = [];

    public array $trash = [];

    public array $files = [];

    public array $marks = [];

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

    public function issueFile(string $user, array $file): array
    {
        $id = ++$this->seq;
        $token = 'tok' . str_pad((string) $id, 20, '0', STR_PAD_LEFT);
        $this->files[$id] = $file + ['user' => $user, 'token' => $token, 'password' => ''];

        return ['id' => $id, 'url' => 'https://files.test/' . $token . '/' . rawurlencode($file['name'])];
    }

    public function updateFile(int $id, array $file): ?string
    {
        if (! isset($this->files[$id])) {
            return null;
        }
        $this->files[$id] = $file + $this->files[$id];

        return 'https://files.test/' . $this->files[$id]['token'] . '/' . rawurlencode($this->files[$id]['name']);
    }

    public function dropFile(int $id): void
    {
        unset($this->files[$id]);
    }

    public function filePath(string $user, int $id): ?string
    {
        foreach ($this->links[$user] ?? [] as $l) {
            if ($l['share_id'] === 'f' . $id) {
                return $l['path'];
            }
        }

        return null;
    }

    public function marks(string $user): array
    {
        return $this->marks[$user] ?? [];
    }

    public function touch(string $user, string $path, bool $onlyNew = false): void
    {
        if ($onlyNew && isset($this->marks[$user][$path])) {
            return;
        }
        $this->marks[$user][$path] = ['last' => date('Y-m-d H:i:s'), 'pinned' => (bool) ($this->marks[$user][$path]['pinned'] ?? false)];
    }

    public function setPinned(string $user, string $path, bool $on): void
    {
        $this->marks[$user][$path] = ['last' => $this->marks[$user][$path]['last'] ?? date('Y-m-d H:i:s'), 'pinned' => $on];
        if (! $on) {
            foreach ($this->marks[$user] as $p => $m) {
                if (PersonalPath::within($p, $path)) {
                    $this->marks[$user][$p]['last'] = date('Y-m-d H:i:s');
                }
            }
        }
    }

    public function forgetMarks(string $user, string $path): void
    {
        foreach (array_keys($this->marks[$user] ?? []) as $p) {
            if (PersonalPath::within($p, $path)) {
                unset($this->marks[$user][$p]);
            }
        }
    }

    public function cloudUsers(): array
    {
        return array_values(array_unique(array_merge(array_column($this->uploads, 'user'), array_keys($this->marks))));
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
        $marks = [];
        foreach ($this->marks[$user] ?? [] as $p => $m) {
            $marks[$map($p)] = $m;
        }
        $this->marks[$user] = $marks;
    }
}
