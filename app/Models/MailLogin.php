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
        if (! in_array($result, ['ok', 'new_device'], true)) {
            \Illuminate\Support\Facades\Log::channel('auth')->warning('FAILED LOGIN mail ip=' . $request->ip() . ' user=' . strtolower($user) . ' result=' . $result);
        }
    }

    /**
     * Неудачные попытки пароля с одного адреса. Считаем только пароль: неверный код
     * двухфакторной защиты — это не подбор, и раньше он копился в тот же счётчик.
     */
    public static function recentFailures(string $ip, int $minutes = 15): int
    {
        return self::query()->where('ip', $ip)->where('result', 'bad_password')->where('created_at', '>=', now()->subMinutes($minutes))->count();
    }

    /**
     * Неудачные попытки по конкретной учётной записи. Весь офис выходит в интернет
     * с одного адреса, поэтому счёт по адресу закрывал вход всем сразу из-за чужих промахов.
     */
    public static function recentUserFailures(string $user, int $minutes = 15): int
    {
        return self::query()->where('user', strtolower($user))->where('result', 'bad_password')->where('created_at', '>=', now()->subMinutes($minutes))->count();
    }

    /** Неверные коды двухфакторной защиты по учётной записи — отдельный счётчик. */
    public static function recentCodeFailures(string $user, int $minutes = 15): int
    {
        return self::query()->where('user', strtolower($user))->where('result', 'bad_code')->where('created_at', '>=', now()->subMinutes($minutes))->count();
    }
}
