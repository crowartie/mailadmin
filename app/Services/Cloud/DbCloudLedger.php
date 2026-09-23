<?php

namespace App\Services\Cloud;

use Illuminate\Support\Facades\DB;

/** Учёт личного облака в MariaDB (webmail_cloud_links, _uploads, _trash). */
final class DbCloudLedger implements CloudLedger
{
    private function row(object $r): array
    {
        return (array) $r;
    }

    private function linkRow(object $r): array
    {
        return ['path' => $r->path, 'share_id' => (string) $r->share_id, 'url' => $r->url,
            'expires_at' => $r->expires_at ? substr((string) $r->expires_at, 0, 10) : null, 'has_password' => (bool) $r->has_password];
    }

    public function links(string $user): array
    {
        $out = [];
        foreach (DB::table('webmail_cloud_links')->where('user', $user)->get() as $r) {
            $out[$r->path] = $this->linkRow($r);
        }

        return $out;
    }

    public function link(string $user, string $path): ?array
    {
        $r = DB::table('webmail_cloud_links')->where('user', $user)->where('path', $path)->first();

        return $r ? $this->linkRow($r) : null;
    }

    public function saveLink(string $user, array $link): void
    {
        DB::table('webmail_cloud_links')->updateOrInsert(
            ['user' => $user, 'path' => $link['path']],
            ['share_id' => $link['share_id'], 'url' => $link['url'], 'expires_at' => $link['expires_at'], 'has_password' => (bool) $link['has_password'],
                'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function forgetLink(string $user, string $path): void
    {
        DB::table('webmail_cloud_links')->where('user', $user)->where('path', $path)->delete();
    }

    public function linksUnder(string $user, string $path): array
    {
        return DB::table('webmail_cloud_links')->where('user', $user)
            ->where(fn ($q) => $q->where('path', $path)->orWhere('path', 'like', addcslashes($path, '%_\\') . '/%'))
            ->get()->map(fn ($r) => $this->linkRow($r))->all();
    }

    public function saveUpload(array $upload): void
    {
        DB::table('webmail_cloud_uploads')->insert($upload + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function upload(string $user, string $id): ?array
    {
        $r = DB::table('webmail_cloud_uploads')->where('id', $id)->where('user', $user)->first();

        return $r ? $this->row($r) : null;
    }

    public function finishUpload(string $id, string $path): void
    {
        DB::table('webmail_cloud_uploads')->where('id', $id)->update(['path' => $path, 'finished_at' => now(), 'updated_at' => now()]);
    }

    public function forgetUpload(string $id): void
    {
        DB::table('webmail_cloud_uploads')->where('id', $id)->delete();
    }

    public function recentUploads(string $user, int $limit): array
    {
        return DB::table('webmail_cloud_uploads')->where('user', $user)->whereNotNull('finished_at')
            ->orderByDesc('finished_at')->limit($limit)->get()->map(fn ($r) => $this->row($r))->all();
    }

    public function trash(string $user): array
    {
        return DB::table('webmail_cloud_trash')->where('user', $user)->orderByDesc('deleted_at')->get()->map(fn ($r) => $this->row($r))->all();
    }

    public function trashItem(string $user, int $id): ?array
    {
        $r = DB::table('webmail_cloud_trash')->where('id', $id)->where('user', $user)->first();

        return $r ? $this->row($r) : null;
    }

    public function addTrash(array $row): int
    {
        return (int) DB::table('webmail_cloud_trash')->insertGetId($row);
    }

    public function forgetTrash(int $id): void
    {
        DB::table('webmail_cloud_trash')->where('id', $id)->delete();
    }

    public function trashOlderThan(\DateTimeInterface $before): array
    {
        return DB::table('webmail_cloud_trash')->where('deleted_at', '<', $before)->get()->map(fn ($r) => $this->row($r))->all();
    }

    public function movePrefix(string $user, string $from, string $to): void
    {
        foreach (['webmail_cloud_links', 'webmail_cloud_uploads'] as $table) {
            $like = addcslashes($from, '%_\\') . '/%';
            foreach (DB::table($table)->where('user', $user)->where(fn ($q) => $q->where('path', $from)->orWhere('path', 'like', $like))->get(['id', 'path']) as $r) {
                $new = $r->path === $from ? $to : $to . substr($r->path, strlen($from));
                DB::table($table)->where('id', $r->id)->update(['path' => $new, 'updated_at' => now()]);
            }
        }
    }
}
