<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

class RuleSet extends Model
{
    protected $table = 'webmail_rules';

    protected $primaryKey = 'user';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['rules' => 'array', 'autoreply' => 'array'];
}
