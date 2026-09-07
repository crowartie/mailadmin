<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Журнал действий администраторов: кто, что, с чем и откуда. */
class AdminAction extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor', 'ip', 'action', 'target', 'details', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    /** Записать действие текущего администратора (или системы). */
    public static function log(string $action, ?string $target = null, ?string $details = null): void
    {
        try {
            $request = request();
            self::create([
                'actor' => Auth::user()?->email ?? 'система',
                'ip' => $request?->ip() ?? '',
                'action' => $action,
                'target' => $target,
                'details' => mb_substr($details, 0, 1000),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // журнал не должен ломать само действие
        }
    }
}
