<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

/** Копия отправленного письма, которую не удалось положить в «Отправленные» с первого раза. */
class SentRetry extends Model
{
    protected $table = 'webmail_sent_retry';

    protected $guarded = [];
}
