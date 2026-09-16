<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Сеанс веб-почты: кто, с какого устройства, когда был последний раз. */
class MailSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'user', 'ip', 'agent', 'device', 'impersonated', 'created_at', 'last_seen_at'];

    protected $casts = ['created_at' => 'datetime', 'last_seen_at' => 'datetime', 'impersonated' => 'boolean'];

    /** Человеческое имя устройства из User-Agent: «Windows · Chrome», «iPhone · Safari». */
    public static function device(?string $ua): string
    {
        $ua = (string) $ua;
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'устройство',
        };
        $browser = match (true) {
            str_contains($ua, 'YaBrowser') => 'Яндекс Браузер',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'браузер',
        };

        return $os . ' · ' . $browser;
    }

    /** Отметить сеанс живым (вызывается middleware не чаще раза в минуту). */
    public static function seen(Request $request, string $user, bool $impersonated = false): void
    {
        $id = $request->session()->getId();
        $row = self::query()->find($id);
        if ($row) {
            if ($row->last_seen_at === null || $row->last_seen_at->lt(now()->subMinute())) {
                $row->update(['last_seen_at' => now(), 'ip' => $request->ip()]);
            }

            return;
        }
        self::create([
            'id' => $id, 'user' => $user, 'ip' => $request->ip(), 'agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'device' => self::device($request->userAgent()), 'impersonated' => $impersonated, 'created_at' => now(), 'last_seen_at' => now(),
        ]);
    }
}
