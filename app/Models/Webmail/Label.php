<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

/** Метка пользователя; в IMAP ей соответствует ключевое слово Lbl_<id>. */
class Label extends Model
{
    protected $table = 'webmail_labels';

    protected $guarded = [];

    public function keyword(): string
    {
        return 'Lbl_' . $this->id;
    }
}
