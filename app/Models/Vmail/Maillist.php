<?php

namespace App\Models\Vmail;

use Illuminate\Database\Eloquent\Model;

/** Рассылка mlmmj (таблица vmail.maillists; настройки живут в /var/vmail/mlmmj/<домен>/<имя>/control). */
class Maillist extends Model
{
    protected $connection = 'vmail';

    protected $table = 'maillists';

    public $timestamps = false;

    protected $casts = ['active' => 'boolean', 'is_newsletter' => 'boolean', 'created' => 'datetime', 'modified' => 'datetime'];
}
