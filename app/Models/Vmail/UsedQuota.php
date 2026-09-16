<?php

namespace App\Models\Vmail;

use Illuminate\Database\Eloquent\Model;

/**
 * Занятое место. Таблицу наполняет Dovecot через свой quota-плагин,
 * писать в неё из админки нельзя — значения перетрутся.
 */
class UsedQuota extends Model
{
    protected $connection = 'vmail';

    protected $table = 'used_quota';

    protected $primaryKey = 'username';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $casts = [
        'bytes' => 'integer',
        'messages' => 'integer',
    ];
}
