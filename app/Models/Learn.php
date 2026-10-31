<?php

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Learn extends Model
{
    use UsesTimestamptz;

    protected $fillable = [
        'user_id',
        'lesson_id',
        'lesson',
        'complete',
        'score',
    ];

    protected $casts = [
        // Автоматически декодирует JSONB в массив при чтении и кодирует при записи
        'lesson'   => 'array',

        // Приводит к объекту Carbon, чтобы можно было делать ->toISOString()
        'complete' => 'datetime',

        // Приводит к float
        'score'    => 'float',
    ];

    // ==================== Связи ====================

    /**
     * Пользователь, проходящий урок.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Урок, который проходят.
     * (Явно указываем внешний ключ, так как поле jsonb тоже называется 'lesson')
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }
}
