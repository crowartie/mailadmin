<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Данные сотрудника, которых нет в схеме iRedMail: отдел, должность, телефоны, личная почта, флаги защиты. */
class EmployeeProfile extends Model
{
    protected $primaryKey = 'username';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['username', 'unit_id', 'title', 'phone', 'mobile', 'personal_email', 'require_2fa', 'login_blocked', 'is_service'];

    protected $casts = ['require_2fa' => 'boolean', 'login_blocked' => 'boolean', 'is_service' => 'boolean'];

    /** Адреса служебных ящиков (кэш минуту; сбрасывается при сохранении профиля). @return string[] */
    public static function serviceUsernames(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('profiles.service', 60, fn () => self::query()->where('is_service', true)->pluck('username')->all());
    }

    protected static function booted(): void
    {
        static::saved(fn () => \Illuminate\Support\Facades\Cache::forget('profiles.service'));
        static::deleted(fn () => \Illuminate\Support\Facades\Cache::forget('profiles.service'));
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
