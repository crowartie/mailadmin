<?php

namespace App\Models\Vmail;

use Illuminate\Database\Eloquent\Model;

/**
 * Псевдоним. Сами адреса назначения лежат не здесь, а в forwardings
 * (строки с is_alias = 1) — так устроен iRedMail начиная с 0.9.
 */
class Alias extends Model
{
    protected $connection = 'vmail';

    protected $table = 'alias';

    protected $primaryKey = 'address';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'created' => 'datetime',
        'modified' => 'datetime',
    ];

    public function targets()
    {
        return $this->hasMany(Forwarding::class, 'address', 'address')
            ->where('is_alias', 1);
    }
}
