<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Журнал входов в веб-почту. */
class MailLogin extends Model
{
    public $timestamps = false;

    protected $fillable = ['user', 'ip', 'agent', 'result', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public static function record(Request $request, string $user, string $result): void
    {
        self::create(['user' => strtolower($user), 'ip' => $request->ip(), 'agent' => mb_substr((string) $request->userAgent(), 0, 300), 'result' => $result, 'created_at' => now()]);
    }

    public static function recentFailures(string $ip, int $minutes = 15): int
    {
        return self::query()->where('ip', $ip)->where('result', 'like', 'bad_%')->where('created_at', '>=', now()->subMinutes($minutes))->count();
    }
}
