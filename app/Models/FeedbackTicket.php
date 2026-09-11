<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Обращение сотрудника. Состояние (status) и итог (resolution) — разные вещи:
 * закрытое обращение всегда имеет итог, по которому видно, что с ним стало.
 */
class FeedbackTicket extends Model
{
    protected $fillable = [
        'user', 'user_name', 'kind', 'subject', 'status', 'resolution', 'priority', 'duplicate_of',
        'area', 'page_url', 'page_title', 'client', 'agent', 'ip', 'app_version', 'context',
        'assigned_to', 'closed_at', 'closed_by', 'last_reply_at', 'new_for_admin', 'new_for_user',
    ];

    protected $casts = [
        'context' => 'array',
        'closed_at' => 'datetime',
        'last_reply_at' => 'datetime',
        'new_for_admin' => 'boolean',
        'new_for_user' => 'boolean',
    ];

    public const KINDS = ['bug' => 'Не работает', 'idea' => 'Предложение', 'question' => 'Вопрос'];

    public const STATUSES = ['new' => 'Новое', 'open' => 'В работе', 'waiting' => 'Ждём ответа', 'closed' => 'Закрыто'];

    public const RESOLUTIONS = [
        'done' => 'Исправлено',
        'not_a_bug' => 'Не ошибка',
        'wont_fix' => 'Не будем исправлять',
        'duplicate' => 'Повтор',
    ];

    public const PRIORITIES = ['low' => 'Низкий', 'normal' => 'Обычный', 'high' => 'Срочно'];

    public function messages(): HasMany
    {
        return $this->hasMany(FeedbackMessage::class, 'ticket_id')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status !== 'closed';
    }

    /** Короткая подпись итога для интерфейса. */
    public function statusLabel(): string
    {
        if ($this->status === 'closed') {
            return self::RESOLUTIONS[$this->resolution] ?? 'Закрыто';
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    public static function openCount(): int
    {
        return (int) self::query()->where('status', '!=', 'closed')->count();
    }

    public static function newCount(): int
    {
        return (int) self::query()->where('status', 'new')->count();
    }
}
