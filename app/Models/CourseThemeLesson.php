<?php

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Связь "тема курса → урок". Не урок и не прохождение — только привязка
 * с локальными параметрами планирования (order, time_gap, type_id).
 */
class CourseThemeLesson extends Model
{
    use UsesTimestamptz;

    protected $fillable = [
        'theme_id',
        'lesson_id',
        'type_id',
        'order',
        'time_gap',
    ];

    protected $casts = [
        'theme_id' => 'integer',
        'lesson_id' => 'integer',
        'type_id' => 'integer',
        'order' => 'integer',
        'time_gap' => 'integer',
    ];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(CourseTheme::class, 'theme_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LessonType::class, 'type_id');
    }
}
