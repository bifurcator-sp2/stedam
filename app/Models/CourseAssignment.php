<?php

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Назначение курса ученику.
 *
 * completion и grade — производные, их пишет только Application-слой
 * (пересчёт стратегиями при событии "урок оценён").
 */
class CourseAssignment extends Model
{
    use UsesTimestamptz;

    protected $fillable = [
        'course_id',
        'student_id',
        'assigned_by',
        'completion',
        'grade',
    ];

    protected $casts = [
        'course_id' => 'integer',
        'student_id' => 'integer',
        'assigned_by' => 'integer',
        'completion' => 'float',
        'grade' => 'float',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
