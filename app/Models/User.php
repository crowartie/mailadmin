<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Администратор панели. Это НЕ почтовый ящик: своя таблица, свой пароль,
 * своё 2FA — чтобы компрометация почты не давала доступ к управлению сервером.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token', 'totp_secret'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'totp_enabled_at' => 'datetime',
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function hasTwoFactor(): bool
    {
        return $this->totp_enabled_at !== null && filled($this->totp_secret);
    }
}
