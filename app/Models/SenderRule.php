<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Общее для всех правило по отправителю (спам или рассылка) — попадает в глобальный Sieve-скрипт Dovecot. */
class SenderRule extends Model
{
    public $timestamps = false;

    protected $fillable = ['kind', 'match', 'value', 'source', 'votes', 'created_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
