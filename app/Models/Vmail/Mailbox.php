<?php

namespace App\Models\Vmail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Почтовый ящик из схемы iRedMail.
 *
 * Ключ — сам адрес, автоинкремента нет. Поля created/modified ведёт
 * почтовый сервер в своём формате, поэтому штатные timestamps выключены.
 */
class Mailbox extends Model
{
    protected $connection = 'vmail';

    protected $table = 'mailbox';

    protected $primaryKey = 'username';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $casts = [
        'quota' => 'integer',
        'active' => 'boolean',
        'created' => 'datetime',
        'modified' => 'datetime',
        'passwordlastchange' => 'datetime',
    ];

    /**
     * Пароль наружу не отдаём никогда — ни в JSON, ни в Inertia-props.
     */
    protected $hidden = ['password'];

    public function domainRelation(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain', 'domain');
    }

    public function usedQuota(): HasOne
    {
        return $this->hasOne(UsedQuota::class, 'username', 'username');
    }

    /**
     * Абсолютный путь к Maildir: storagebasedirectory + storagenode + maildir.
     * Именно так его собирает Dovecot, руками путь не конструировать.
     */
    public function getMaildirPathAttribute(): string
    {
        return rtrim($this->storagebasedirectory, '/')
            . '/' . trim($this->storagenode, '/')
            . '/' . ltrim($this->maildir, '/');
    }

    /**
     * Квота в мегабайтах; 0 в схеме означает «без ограничения».
     */
    public function getQuotaMbAttribute(): ?int
    {
        return $this->quota > 0 ? (int) $this->quota : null;
    }

    /** Только сотрудники: активные ящики без служебных (info@, сканеры, принтеры). */
    public function scopePeople($query)
    {
        $query->where('active', 1);
        $service = \App\Models\EmployeeProfile::serviceUsernames();

        return $service ? $query->whereNotIn('username', $service) : $query;
    }

    public function scopeSearch($query, ?string $term)
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('username', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%");
        });
    }
}
