<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AdminLogin extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['email', 'ip', 'user_agent', 'result'];

    protected $casts = ['created_at' => 'datetime'];

    public static function record(Request $request, string $email, string $result): void
    {
        static::create([
            'email' => strtolower($email),
            'ip' => (string) $request->ip(),
            'user_agent' => mb_strimwidth((string) $request->userAgent(), 0, 500),
            'result' => $result,
        ]);
    }

    /**
     * Сколько неудач с этого IP за последние минуты — простая защита от перебора
     * на уровне приложения, пока нет fail2ban перед админкой.
     */
    public static function recentFailures(string $ip, int $minutes = 15): int
    {
        return static::where('ip', $ip)
            ->where('result', '!=', 'ok')
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }
}
