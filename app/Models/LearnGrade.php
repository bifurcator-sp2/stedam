<?php

namespace App\Models;

use App\Enums\LearnGradeStatus;
use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Тонкая Eloquent-модель «Оценка Прохождения» (Infrastructure/Persistence).
 *
 * Доменной логики не содержит: правила выставления оценки — в Application-слое.
 * pre_grade/pre_grade_confidence — предварительная оценка и её уверенность,
 * grade — итоговая оценка преподавателя. Значения в (0..1) или NULL.
 */
class LearnGrade extends Model
{
    use UsesTimestamptz;

    protected $fillable = [
        'learn_id',
        'status',
        'pre_grade',
        'pre_grade_confidence',
        'grade',
        'grader_user_id',
        'grader_comment',
        'error',
    ];

    /**
     * Статус обработки оценки. Значения задаются в миграции
     * (enum: pending, in_progress, pre_graded, graded).
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PRE_GRADED = 'pre_graded';
    public const STATUS_GRADED = 'graded';

    protected $casts = [
        'learn_id'             => 'integer',
        'status'               => LearnGradeStatus::class,
        'pre_grade'            => 'float',
        'pre_grade_confidence' => 'float',
        'grade'                => 'float',
        'grader_user_id'       => 'integer',
    ];

    // ==================== Связи ====================

    public function learn(): BelongsTo
    {
        return $this->belongsTo(Learn::class, 'learn_id');
    }

    /**
     * Преподаватель, выставивший оценку.
     */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grader_user_id');
    }
}
