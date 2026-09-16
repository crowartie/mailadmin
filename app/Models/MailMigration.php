<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Строка переноса: один ящик со старого сервера → один наш ящик. Пароль старого сервера хранится зашифрованным. */
class MailMigration extends Model
{
    public const STATUSES = ['new' => 'не запускался', 'queued' => 'в очереди', 'running' => 'идёт перенос', 'done' => 'готово', 'failed' => 'ошибка'];

    protected $fillable = ['source_host', 'source_port', 'source_ssl', 'source_login', 'source_password', 'target', 'what', 'status', 'stats', 'dav_stats', 'options', 'error', 'log_path', 'created_by', 'started_at', 'finished_at'];

    protected $casts = ['source_ssl' => 'bool', 'source_password' => 'encrypted', 'stats' => 'array', 'dav_stats' => 'array', 'options' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function isBusy(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
