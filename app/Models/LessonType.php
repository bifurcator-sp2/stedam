<?php

namespace App\Models;

use App\Models\Concerns\UsesTimestamptz;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тип урока (справочник).
 *
 * Тонкая модель: доменных правил здесь нет. Порог допуска к следующему уроку
 * (`next_lesson_threshold`) — доля 0..1, живёт в БД и редактируется через
 * справочник, а не в коде.
 *
 * Курсы используют тип только для вычисления порога (см. CourseLesson),
 * интерпретация типа (домашка под уроком, контрольная в конце) — задача UI.
 */
class LessonType extends Model
{
    use UsesTimestamptz;

    protected $fillable = [
        'code',
        'next_lesson_threshold',
        'color',
        'sort',
    ];

    protected $casts = [
        'next_lesson_threshold' => 'float',
        'sort' => 'integer',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(LessonTypeTranslation::class);
    }

    /**
     * Название на текущей локали с fallback на конфигурируемую и первую
     * доступную. Как у App\Models\Taxonomy::getTitleAttribute().
     */
    public function getTitleAttribute(): ?string
    {
        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        return $this->translations->firstWhere('locale', $locale)?->title
            ?? $this->translations->firstWhere('locale', $fallback)?->title
            ?? $this->translations->first()?->title;
    }

    public function getDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        return $this->translations->firstWhere('locale', $locale)?->description
            ?? $this->translations->firstWhere('locale', $fallback)?->description
            ?? $this->translations->first()?->description;
    }
}
