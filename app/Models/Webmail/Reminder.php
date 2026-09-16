<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    protected $table = 'webmail_reminders';

    protected $guarded = [];

    protected $casts = ['remind_at' => 'datetime'];
}
