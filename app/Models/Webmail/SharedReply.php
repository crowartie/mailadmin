<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

/** Ответ сотрудника на письмо из общей папки (обращение №57). См. SharedReplies. */
class SharedReply extends Model
{
    protected $table = 'webmail_shared_replies';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['replied_at' => 'datetime', 'size' => 'integer'];
}
