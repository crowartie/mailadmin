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
        'notify_browser' => false,
        'ask_rule_on_move' => true,
        'shared_mark_seen' => false,  // в чужой (общей) папке открытие письма не отмечает его прочитанным
        'undo_seconds' => 5,
        'quick_replies' => ['Спасибо, получил.', 'Принято, сделаю.', 'Давайте обсудим по телефону.'],
        'shortcuts' => true,
        'preview' => true,
        'unread_highlight' => true,   // непрочитанные — полоска слева и тема цветом (обращение №26)
        'unread_color' => '',         // свой цвет подсветки (#rrggbb); пусто — синий темы
        'show_images' => 'ask',
        'totp_enabled' => false,
        'ui_simple' => false,         // простой вид: меньше кнопок на панели письма и в меню, галочки по наведению
    ];

    /** Ключи, которые никогда не уходят в интерфейс. */
    private const SECRETS = ['totp_secret'];

    /** @return array<string,mixed> */
    public static function for(string $user, bool $withSecrets = false): array
    {
        $row = static::find($user);
        $data = array_merge(self::DEFAULTS, $row?->data ?? []);
        if (! $withSecrets) {
            foreach (self::SECRETS as $k) {
                unset($data[$k]);
            }
        }

        return $data;
    }

    /** Настройки из формы: только известные ключи. */
    public static function save_(string $user, array $patch): array
    {
        return self::patch($user, array_intersect_key($patch, self::DEFAULTS));
    }

    /** Любые ключи (служебные: секрет 2FA и т.п.). */
    public static function patch(string $user, array $patch): array
    {
        $row = static::firstOrNew(['user' => $user]);
        $data = array_merge($row->data ?? [], $patch);
        foreach ($patch as $k => $v) {
            if ($v === null) {
                unset($data[$k]);
            }
        }
        $row->data = $data;
        $row->save();

        return array_diff_key(array_merge(self::DEFAULTS, $data), array_flip(self::SECRETS));
    }
}
