<?php

namespace App\Models\Vmail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Почтовый домен. Поля mailboxes/aliases/maillists — это ЛИМИТЫ,
 * а не счётчики: -1 запрещает создание, 0 снимает ограничение.
 */
class Domain extends Model
{
    protected $connection = 'vmail';

    protected $table = 'domain';

    protected $primaryKey = 'domain';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $casts = [
        'aliases' => 'integer',
        'mailboxes' => 'integer',
        'maillists' => 'integer',
        'maxquota' => 'integer',
        'quota' => 'integer',
        'backupmx' => 'boolean',
        'active' => 'boolean',
        'created' => 'datetime',
        'modified' => 'datetime',
    ];

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class, 'domain', 'domain');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(Alias::class, 'domain', 'domain');
    }
}
