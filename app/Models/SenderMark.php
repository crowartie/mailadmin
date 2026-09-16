<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Личное решение сотрудника: адрес/домен — не спам, спам или рассылка. */
class SenderMark extends Model
{
    public const KINDS = ['ham' => 'не спам', 'spam' => 'спам', 'lists' => 'рассылка'];

    public $timestamps = false;

    protected $fillable = ['user', 'kind', 'match', 'value', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
