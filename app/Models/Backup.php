<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Запись о резервной копии (журнал запусков mailadmin-backup). */
class Backup extends Model
{
    public $timestamps = false;

    protected $fillable = ['file', 'size', 'seconds', 'parts', 'status', 'error', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
