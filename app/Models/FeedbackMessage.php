<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Сообщение в обращении: первое от сотрудника, дальше переписка с администратором. */
class FeedbackMessage extends Model
{
    protected $fillable = ['ticket_id', 'author', 'author_role', 'text', 'file'];
}
