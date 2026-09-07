<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Пароль приложения: отдельный пароль для телефона или почтовой программы.
 * Dovecot проверяет его вторым passdb; основной пароль при этом не раскрывается.
 */
class AppPassword extends Model
{
    protected $fillable = ['username', 'name', 'password', 'active', 'last_used_at'];

    protected $hidden = ['password'];

    protected $casts = ['active' => 'boolean', 'last_used_at' => 'datetime'];

    /** Новый пароль: 4 группы по 4 символа, как у Google. @return array{plain:string,hash:string} */
    public static function generate(): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $plain = '';
        for ($i = 0; $i < 16; $i++) {
            $plain .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $salt = random_bytes(8);

        return ['plain' => $plain, 'hash' => '{SSHA512}' . base64_encode(hash('sha512', $plain . $salt, true) . $salt)];
    }
}
