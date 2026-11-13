<?php

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Тонкая Eloquent-модель курса.
 *
 * Доменные правила (порядок тем/уроков, блокировки locale/tax_id, complexity)
 * живут в App\Domain\Course\Entities\Course. Эта модель — только транспорт
 * по БД: связи, касты, имя таблицы.
 */
class Course extends Model
{
    use SoftDeletes, UsesTimestamptz;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'next_lesson_threshold',
        'locale',
        'tax_id',
        'complexity',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'tax_id' => 'integer',
        'next_lesson_threshold' => 'float',
        'complexity' => 'float',
    ];

    /**
     * Автор курса. NULL — системный курс.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function taxonomy(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::class, 'tax_id');
    }

    /**
     * Темы курса — всегда в порядке "order".
     */
    public function themes(): HasMany
    {
        return $this->hasMany(CourseTheme::class)->orderBy('order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CourseAssignment::class);
    }
}
