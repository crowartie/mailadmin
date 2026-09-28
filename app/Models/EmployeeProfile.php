<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Данные сотрудника, которых нет в схеме iRedMail: отдел, должность, телефоны, личная почта, флаги защиты. */
class EmployeeProfile extends Model
{
    protected $primaryKey = 'username';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['username', 'middle_name', 'unit_id', 'title', 'phone', 'mobile', 'personal_email', 'require_2fa', 'login_blocked', 'is_service'];

    protected $casts = ['require_2fa' => 'boolean', 'login_blocked' => 'boolean', 'is_service' => 'boolean'];

    /** Адреса служебных ящиков (кэш минуту; сбрасывается при сохранении профиля). @return string[] */
    public static function serviceUsernames(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('profiles.service', 60, fn () => self::query()->where('is_service', true)->pluck('username')->all());
    }

    /** Ящики с закрытым входом: в книге «Сотрудники» и подсказках адресов их быть не должно. */
    public static function blockedUsernames(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('profiles.blocked', 60, fn () => self::query()->where('login_blocked', true)->pluck('username')->all());
    }

    protected static function booted(): void
    {
        $forget = function () {
            \Illuminate\Support\Facades\Cache::forget('profiles.service');
            \Illuminate\Support\Facades\Cache::forget('profiles.blocked');
        };
        static::saved($forget);
        static::deleted($forget);
    }

    public static function for(string $username): self
    {
        return self::query()->firstOrNew(['username' => strtolower($username)]);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
