<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Правило сотрудника, прочитанное из его Sieve-скрипта на почтовом сервере.
 * Хранится у нас только как витрина: источник правды — скрипт в Dovecot,
 * сюда его переносит команда `rules:sync`.
 */
class SieveRule extends Model
{
    protected $fillable = [
        'owner', 'owner_name', 'kind', 'condition', 'action', 'active', 'until', 'position', 'raw', 'synced_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'until' => 'date',
        'synced_at' => 'datetime',
    ];

    public function scopeSearch($query, ?string $term)
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('owner', 'like', "%{$term}%")
                ->orWhere('owner_name', 'like', "%{$term}%")
                ->orWhere('condition', 'like', "%{$term}%")
                ->orWhere('action', 'like', "%{$term}%");
        });
    }

    public function scopeOfKind($query, ?string $kind)
    {
        return match ($kind) {
            'vacation' => $query->where('kind', 'vacation'),
            'redirect' => $query->where('kind', 'redirect'),
            'disabled' => $query->where('active', false),
            default => $query,
        };
    }
}
