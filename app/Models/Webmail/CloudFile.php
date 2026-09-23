<?php

namespace App\Models\Webmail;

use Illuminate\Database\Eloquent\Model;

/**
 * Файл в своём хранилище больших вложений (см. App\Services\Cloud\LocalFiles).
 *
 * @property int $id
 * @property string $user
 * @property string $token
 * @property string $name
 * @property int $size
 * @property string $mime
 * @property string $path
 * @property string $source    local — файл на диске почты; nc — в облаке сотрудника (Nextcloud)
 * @property ?string $password хэш пароля ссылки (только у файлов облака)
 * @property ?string $message_id
 * @property ?\Carbon\Carbon $expires_at
 * @property int $downloads
 * @property ?\Carbon\Carbon $last_download_at
 */
class CloudFile extends Model
{
    /** Файл лежит в облаке сотрудника, а не на диске почты. */
    public const SOURCE_CLOUD = 'nc';

    protected $table = 'webmail_files';

    protected $guarded = [];

    protected $hidden = ['password'];

    protected $casts = ['expires_at' => 'datetime', 'last_download_at' => 'datetime', 'checked_at' => 'datetime', 'size' => 'int', 'downloads' => 'int'];

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isCloud(): bool
    {
        return $this->source === self::SOURCE_CLOUD;
    }

    /** Полный путь на диске. */
    public function fullPath(): string
    {
        return storage_path('app/files/' . $this->path);
    }

    /** Ссылка, которую видит получатель. */
    public function url(): string
    {
        return \App\Services\Cloud\LocalFiles::base() . '/' . $this->token . '/' . rawurlencode($this->name);
    }

    /** Что отдаём интерфейсу почты. */
    public function toCard(string $viewer): array
    {
        return [
            'token' => $this->token,
            'name' => $this->name,
            'size' => $this->size,
            'type' => $this->mime,
            'url' => $this->url(),
            'expires' => $this->expires_at?->toDateString(),
            'expired' => $this->expired(),
            'mine' => strcasecmp($this->user, $viewer) === 0,
            'downloads' => $this->downloads,
            'subject' => (string) ($this->subject ?? ''),
            'preview' => \App\Services\Cloud\LocalFiles::previewable($this),
            // Файл из облака продлевают и удаляют в разделе «Облако», в архив «Скачать все» он не идёт.
            'cloud' => $this->isCloud(),
        ];
    }
}
