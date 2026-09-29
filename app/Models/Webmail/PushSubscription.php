<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

/** Push-подписка одного браузера или телефона сотрудника. См. PushNotifier. */
class PushSubscription extends Model
{
    protected $table = 'webmail_push_subscriptions';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime', 'last_sent_at' => 'datetime', 'failures' => 'integer'];
}
