<?php

namespace App\Models;

use App\Domain\Learn\Enums\LearnStatus as LearnStatusEnum;
use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Тонкая Eloquent-модель «Событие смены статуса Прохождения» (Infrastructure/Persistence).
 *
 * Append-only лог аудита. Пишется через порт StatusLoggerInterface
 * (асинхронный батч), а не напрямую из домена.
 */
class LearnStatus extends Model
{
    use UsesTimestamptz;

    /**
     * Таблица append-only: Laravel-овские updated_at не нужны,
     * есть только технический created_at (см. миграцию).
     */
    public $timestamps = false;

    protected $fillable = [
        'learn_id',
        'lesson_id',
        'status',
        'status_date',
        'schedule_date',
        'created_at',
    ];

    protected $casts = [
        'status'        => LearnStatusEnum::class,
        'status_date'   => 'datetime',
        'schedule_date' => 'datetime',
        'created_at'    => 'datetime',
    ];

    // ==================== Связи ====================

    public function learn(): BelongsTo
    {
        return $this->belongsTo(Learn::class, 'learn_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }
}
