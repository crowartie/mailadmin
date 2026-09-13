<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Адрес нашего домена, которому разрешено приходить с серверов стороннего сервиса (mail.ru, Яндекс…) без входа на наш SMTP. */
class ExternalSender extends Model
{
    protected $fillable = ['address', 'provider', 'note', 'created_by'];
}
