<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

class Outbox extends Model
{
    protected $table = 'webmail_outbox';

    protected $guarded = [];

    protected $casts = ['send_at' => 'datetime', 'recipients' => 'array'];
}
