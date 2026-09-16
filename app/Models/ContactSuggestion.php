<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Контакт, который сотрудник предложил в общую книгу «Контакты компании»; решает администратор. */
class ContactSuggestion extends Model
{
    protected $fillable = ['user', 'fn', 'email', 'vcard', 'status', 'note'];
}
