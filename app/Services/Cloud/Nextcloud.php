<?php

namespace App\Services\Cloud;

use App\Models\AppSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Nextcloud для больших вложений: одна служебная учётка (получена через штатный Login Flow v2),
 * папка «Почта» в её облаке, файлы кладутся по WebDAV, ссылка — публичная (OCS share) со сроком.
 */
class Nextcloud
{
    public const GROUP = 'cloud';

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        $s = AppSetting::group(self::GROUP);
        if (! empty($s['app_password'])) {
            try {
                $s['app_password'] = Crypt::decryptString($s['app_password']);
            } catch (\Throwable) {
                $s['app_password'] = '';
            }
        }

        return $s;
    }

    public static function enabled(): bool
    {
        $s = self::settings();

        return ! empty($s['enabled']) && filled($s['url']) && filled($s['login']) && filled($s['app_password']);
    }

    /** Порог в байтах, начиная с которого вложение уходит в облако. */
    public static function threshold(): int
    {
        return max(1, (int) (self::settings()['threshold_mb'] ?? 10)) * 1048576;
    }

    private function base(): string
    {
        return rtrim((string) self::settings()['url'], '/');
    }

    private function http(): PendingRequest
    {
        $s = self::settings();

        return Http::withBasicAuth((string) $s['login'], (string) $s['app_password'])->withHeaders(['OCS-APIRequest' => 'true'])->timeout(60);
    }

    private function davRoot(): string
    {
        return $this->base() . '/remote.php/dav/files/' . rawurlencode((string) self::settings()['login']);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }

    // ── Подключение ──────────────────────────────────────────────────────

    /** Шаг 1 Login Flow v2: сервер выдаёт ссылку для входа и адрес опроса. @return array{login:string,token:string,endpoint:string} */
    public static function loginFlowStart(string $url): array
    {
        $url = rtrim(trim($url), '/');
        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $r = Http::timeout(15)->withHeaders(['User-Agent' => 'Почта ' . config('areas.default_domain')])->post($url . '/index.php/login/v2');
        if (! $r->ok() || ! isset($r['login'], $r['poll']['token'], $r['poll']['endpoint'])) {
            throw new \RuntimeException('Это не Nextcloud или адрес недоступен (ответ ' . $r->status() . ')');
        }

        return ['url' => $url, 'login' => $r['login'], 'token' => $r['poll']['token'], 'endpoint' => $r['poll']['endpoint']];
    }

    /** Шаг 2: опрос; пока пользователь не подтвердил — null. @return array{server:string,loginName:string,appPassword:string}|null */
    public static function loginFlowPoll(string $endpoint, string $token): ?array
    {
        $r = Http::timeout(15)->asForm()->post($endpoint, ['token' => $token]);
        if ($r->status() === 404) {
            return null;
        }
        if (! $r->ok() || ! isset($r['appPassword'])) {
            throw new \RuntimeException('Nextcloud ответил ' . $r->status());
        }

        return ['server' => (string) $r['server'], 'loginName' => (string) $r['loginName'], 'appPassword' => (string) $r['appPassword']];
    }

    public static function saveCredentials(string $url, string $login, string $appPassword): void
    {
        AppSetting::put(self::GROUP, ['url' => rtrim($url, '/'), 'login' => $login, 'app_password' => Crypt::encryptString($appPassword), 'enabled' => true]);
    }

    public static function disconnect(): void
    {
        AppSetting::put(self::GROUP, ['login' => '', 'app_password' => '', 'enabled' => false]);
    }

    // ── Проверка и папка ─────────────────────────────────────────────────

    /** @return array{ok:bool,user:string,free:?int,used:?int,folder:bool,message:string,version:string} */
    public function status(): array
    {
        $s = self::settings();
        if (! filled($s['login']) || ! filled($s['app_password'])) {
            return ['ok' => false, 'user' => '', 'free' => null, 'used' => null, 'folder' => false, 'message' => 'не подключено', 'version' => ''];
        }
        try {
            $r = $this->http()->withBody('<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:quota-available-bytes/><d:quota-used-bytes/></d:prop></d:propfind>', 'application/xml')
                ->withHeaders(['Depth' => '0'])->send('PROPFIND', $this->davRoot() . '/');
            if ($r->status() === 401) {
                return ['ok' => false, 'user' => $s['login'], 'free' => null, 'used' => null, 'folder' => false, 'message' => 'Nextcloud не принимает пароль приложения — подключите заново', 'version' => ''];
            }
            if ($r->status() !== 207) {
                return ['ok' => false, 'user' => $s['login'], 'free' => null, 'used' => null, 'folder' => false, 'message' => 'WebDAV ответил ' . $r->status(), 'version' => ''];
            }
            $free = preg_match('~quota-available-bytes>(-?\d+)<~', $r->body(), $m) ? (int) $m[1] : null;
            $used = preg_match('~quota-used-bytes>(\d+)<~', $r->body(), $m) ? (int) $m[1] : null;
            $folder = $this->exists($this->folder());
            $version = '';
            try {
                $st = Http::timeout(10)->get($this->base() . '/status.php');
                $version = (string) ($st['versionstring'] ?? '');
            } catch (\Throwable) {
            }

            return ['ok' => true, 'user' => $s['login'], 'free' => $free !== null && $free >= 0 ? $free : null, 'used' => $used, 'folder' => $folder, 'message' => $folder ? 'папка «' . $this->folder() . '» на месте' : 'папки «' . $this->folder() . '» нет — будет создана', 'version' => $version];
        } catch (\Throwable $e) {
            return ['ok' => false, 'user' => $s['login'], 'free' => null, 'used' => null, 'folder' => false, 'message' => 'нет связи: ' . mb_substr($e->getMessage(), 0, 160), 'version' => ''];
        }
    }

    public function folder(): string
    {
        return trim((string) (self::settings()['folder'] ?? 'Почта'), '/') ?: 'Почта';
    }

    public function exists(string $path): bool
    {
        $r = $this->http()->withHeaders(['Depth' => '0'])->send('PROPFIND', $this->davRoot() . '/' . $this->encodePath($path));

        return $r->status() === 207;
    }

    /** Создать папку вместе с родителями (MKCOL по одной). */
    public function ensureFolder(string $path): void
    {
        $acc = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            $acc .= ($acc === '' ? '' : '/') . $part;
            if ($this->exists($acc)) {
                continue;
            }
            $r = $this->http()->send('MKCOL', $this->davRoot() . '/' . $this->encodePath($acc));
            if (! in_array($r->status(), [201, 405], true)) {
                throw new \RuntimeException('Не удалось создать папку «' . $acc . '» (ответ ' . $r->status() . ')');
            }
        }
    }

    // ── Файлы и ссылки ───────────────────────────────────────────────────

    /** Положить файл и вернуть публичную ссылку. @return array{url:string,path:string,expires:?string} */
    public function publish(string $localFile, string $name, string $user): array
    {
        $s = self::settings();
        $dir = $this->folder() . '/' . preg_replace('/[^a-z0-9._@-]/i', '_', strtolower($user)) . '/' . date('Y-m');
        $this->ensureFolder($dir);
        $safe = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $name) ?: 'file';
        $path = $dir . '/' . $safe;
        // Не затирать одноимённый файл прошлой отправки.
        if ($this->exists($path)) {
            $ext = pathinfo($safe, PATHINFO_EXTENSION);
            $base = $ext !== '' ? substr($safe, 0, -strlen($ext) - 1) : $safe;
            $path = $dir . '/' . $base . '-' . date('His') . ($ext !== '' ? '.' . $ext : '');
        }
        $stream = fopen($localFile, 'rb');
        try {
            $r = $this->http()->timeout(600)->withBody($stream, 'application/octet-stream')->put($this->davRoot() . '/' . $this->encodePath($path));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if (! in_array($r->status(), [201, 204], true)) {
            throw new \RuntimeException('Файл не загрузился в облако (ответ ' . $r->status() . ')');
        }
        $expires = ((int) ($s['expire_days'] ?? 30)) > 0 ? now()->addDays((int) $s['expire_days'])->toDateString() : null;
        $form = ['path' => '/' . $path, 'shareType' => 3, 'permissions' => 1];
        if ($expires) {
            $form['expireDate'] = $expires;
        }
        if (! empty($s['link_password'])) {
            $form['password'] = $s['link_password'];
        }
        $share = $this->http()->asForm()->withHeaders(['Accept' => 'application/json'])->post($this->base() . '/ocs/v2.php/apps/files_sharing/api/v1/shares', $form);
        $url = (string) ($share['ocs']['data']['url'] ?? '');
        if (! $share->ok() || $url === '') {
            throw new \RuntimeException('Ссылка не создана: ' . mb_substr((string) ($share['ocs']['meta']['message'] ?? $share->status()), 0, 160));
        }

        return ['url' => $url, 'path' => $path, 'expires' => $expires];
    }
}
