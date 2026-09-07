<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'webmail_settings';

    protected $primaryKey = 'user';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['data' => 'array'];

    public const DEFAULTS = [
        'display_name' => '',
        'signature' => '',
        'signature_reply' => true,
        'theme' => 'light',
        'density' => 'normal',
        'reply_all' => false,
        'undo_seconds' => 5,
        'quick_replies' => ['Спасибо, получил.', 'Принято, сделаю.', 'Давайте обсудим по телефону.'],
        'shortcuts' => true,
        'preview' => true,
        'show_images' => 'ask',
    ];

    /** @return array<string,mixed> */
    public static function for(string $user): array
    {
        $row = static::find($user);

        return array_merge(self::DEFAULTS, $row?->data ?? []);
    }

    public static function save_(string $user, array $patch): array
    {
        $row = static::firstOrNew(['user' => $user]);
        $data = array_merge($row->data ?? [], array_intersect_key($patch, self::DEFAULTS));
        $row->data = $data;
        $row->save();

        return array_merge(self::DEFAULTS, $data);
    }
}
