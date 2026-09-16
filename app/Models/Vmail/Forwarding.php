<?php

namespace App\Models\Vmail;

use Illuminate\Database\Eloquent\Model;

/**
 * Универсальная таблица маршрутизации адресов. Одна и та же строка
 * описывает разные сущности, различаются флагами:
 *   address == forwarding            — сам ящик (обязательная строка, иначе почта не придёт);
 *   is_forwarding = 1                — пересылка на другой адрес;
 *   is_alias = 1                     — назначение алиаса;
 *   is_list / is_maillist = 1        — рассылка.
 */
class Forwarding extends Model
{
    protected $connection = 'vmail';

    protected $table = 'forwardings';

    public $timestamps = false;

    protected $casts = [
        'is_maillist' => 'boolean',
        'is_list' => 'boolean',
        'is_forwarding' => 'boolean',
        'is_alias' => 'boolean',
        'active' => 'boolean',
    ];

    protected $fillable = [
        'address', 'forwarding', 'domain', 'dest_domain',
        'is_maillist', 'is_list', 'is_forwarding', 'is_alias', 'active',
    ];

    /** Строка «сам себе», без которой ящик не получает почту. */
    public function scopeSelfDelivery($query)
    {
        return $query->whereColumn('address', 'forwarding');
    }
}
