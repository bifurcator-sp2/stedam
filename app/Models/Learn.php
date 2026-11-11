<?php

namespace App\Models;

use App\Domain\Learn\Enums\LearnStatus;
use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тонкая Eloquent-модель «Прохождение урока» (Infrastructure/Persistence).
 *
 * Доменной логики не содержит: правила жизненного цикла — в
 * App\Domain\Learn\Entities\Learn. Эта модель только маппит БД.
 */
class Learn extends Model
{
    use UsesTimestamptz;

    protected $fillable = [
        'user_id',
        'lesson_id',
        'lesson_snapshot',
        'status',
        'status_date',
        'schedule_date',
        'started_at',
        'score',
        'graded_by',
        'answers',
        'questions',
        'complete_date',
        'archived_at',
    ];

    protected $casts = [
        'status'          => LearnStatus::class,

        // JSONB → array при чтении / обратно при записи.
        'answers'         => 'array',
        'questions'       => 'array',

        'score'           => 'float',

        'status_date'     => 'datetime',
        'schedule_date'   => 'datetime',
        'started_at'      => 'datetime',
        'complete_date'   => 'datetime',
        'archived_at'     => 'datetime',
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
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }

    /**
     * Преподаватель, выставивший оценку.
     */
    public function gradedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /**
     * История смен статуса (аудит).
     */
    public function statuses(): HasMany
    {
        return $this->hasMany(LearnStatus::class, 'learn_id');
    }
}
