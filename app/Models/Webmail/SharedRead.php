<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

/** Отметка «сотрудник прочитал письмо в общей папке» (обращение №51). См. SharedReads. */
class SharedRead extends Model
{
    protected $table = 'webmail_shared_reads';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['read_at' => 'datetime'];
}
