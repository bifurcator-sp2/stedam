<?php

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Связь "тема курса → урок". Не урок и не прохождение — только привязка
 * с локальными параметрами планирования (order, time_gap).
 *
 * Тип урока здесь не хранится: он берётся из самого урока (lessons.type),
 * а time_gap вычисляется при сохранении как lesson_types.time_gap
 * × courses.time_gap_multipler.
 */
class CourseThemeLesson extends Model
{
    use UsesTimestamptz;

    protected $fillable = [
        'theme_id',
        'lesson_id',
        'order',
        'time_gap',
    ];

    protected $casts = [
        'theme_id' => 'integer',
        'lesson_id' => 'integer',
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
}
