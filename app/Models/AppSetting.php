<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Настройки сервера, которые живут в базе приложения (пороги уведомлений, политики, каналы). */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];

    public const DEFAULTS = [
        'security' => ['min_password' => 10, 'password_days' => 365, 'admin_2fa' => false, 'all_2fa_internet' => false, 'notify_new_device' => true, 'app_passwords' => true, 'telegram_security' => true],
        'alerts' => ['queue' => true, 'queue_size' => 50, 'queue_age_hours' => 2, 'disk' => true, 'disk_pct' => 85, 'services' => true, 'backup' => true, 'admin_login' => false, 'digest' => true, 'digest_time' => '09:00'],
        'channels' => ['emails' => '', 'telegram_token' => '', 'telegram_chat' => '', 'telegram_proxy' => ''],
        'backup' => ['dir' => '/var/backups/mail', 'time' => '03:00', 'keep_daily' => 14, 'keep_weekly' => 6, 'mail' => true, 'db' => true, 'config' => true, 'vm_snapshot' => false],
        'quarantine' => ['digest' => true, 'digest_time' => '09:00', 'keep_days' => 14],
        'fail2ban' => ['maxretry' => 5, 'findtime' => 10, 'bantime_hours' => 24],
        'cloud' => ['enabled' => false, 'url' => '', 'login' => '', 'app_password' => '', 'folder' => 'Почта', 'threshold_mb' => 10, 'expire_days' => 30, 'link_password' => ''],
        'reports' => ['mailbox' => ''],
        'migration' => ['host' => '', 'port' => 993, 'ssl' => true, 'dav_url' => ''],
        'mtasts' => ['enabled' => false, 'mode' => 'testing', 'max_age' => 604800, 'id' => ''],
        'limits' => ['default_quota_mb' => 2048, 'blocked_ext' => 'exe, scr, bat, cmd, js, vbs, pif', 'max_recipients' => 100],
    ];

    /** @return array<string,mixed> */
    public static function group(string $key): array
    {
        $all = Cache::remember('app_settings', 60, fn () => self::query()->pluck('value', 'key')->all());
        $stored = $all[$key] ?? [];

        return array_merge(self::DEFAULTS[$key] ?? [], is_array($stored) ? $stored : []);
    }

    public static function put(string $key, array $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => array_merge(self::group($key), $value)]);
        Cache::forget('app_settings');
    }
}
