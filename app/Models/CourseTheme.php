<?php

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тема курса. Тонкая модель: правила — в App\Domain\Course\Entities\CourseTheme.
 */
class CourseTheme extends Model
{
    use UsesTimestamptz;

    protected $fillable = [
        'course_id',
        'title',
        'order',
    ];

    protected $casts = [
        'course_id' => 'integer',
        'order' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Связи с уроками — всегда в порядке "order".
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(CourseThemeLesson::class, 'theme_id')->orderBy('order');
    }
}
