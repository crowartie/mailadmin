<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Данные сотрудника, которых нет в схеме iRedMail: отдел, должность, телефоны, личная почта, флаги защиты. */
class EmployeeProfile extends Model
{
    protected $primaryKey = 'username';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['username', 'unit_id', 'title', 'phone', 'mobile', 'personal_email', 'require_2fa', 'login_blocked'];

    protected $casts = ['require_2fa' => 'boolean', 'login_blocked' => 'boolean'];

    public static function for(string $username): self
    {
        return self::query()->firstOrNew(['username' => strtolower($username)]);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
