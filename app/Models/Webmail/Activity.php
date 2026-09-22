<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

/** Строка журнала действий сотрудника в веб-почте (см. RecordActivity). */
class Activity extends Model
{
    protected $table = 'webmail_activity';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['at' => 'datetime', 'ms' => 'int', 'status' => 'int', 'master' => 'bool'];
}
