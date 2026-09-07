<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

/** Кому пользователь писал: для автодополнения адресов. */
class Recent extends Model
{
    protected $table = 'webmail_recents';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['last_at' => 'datetime'];

    public static function remember(string $user, string $email, ?string $name): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || $email === $user) {
            return;
        }
        $row = static::firstOrNew(['user' => $user, 'email' => $email]);
        $row->uses = ($row->exists ? $row->uses : 0) + 1;
        if ($name) {
            $row->name = $name;
        }
        $row->last_at = now();
        $row->save();
    }
}
