<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

class Snooze extends Model
{
    protected $table = 'webmail_snoozes';

    protected $guarded = [];

    protected $casts = ['until' => 'datetime'];
}
