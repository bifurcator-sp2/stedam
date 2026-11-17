<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Queries;

use App\Application\Lessons\DTOs\LessonTypeOption;
use App\Application\Lessons\Ports\LessonTypeQueryInterface;
use App\Models\LessonType;

/**
 * Справочник типов урока из таблицы `lesson_types`.
 *
 * Переводы подгружаются только на текущую локаль и fallback: `title` и
 * `description` считаются аксессорами модели LessonType. Остальные локали
 * загружать незачем — они не попадают в ответ.
 *
 * Правило выбора перевода (текущая локаль → `app.fallback_locale` → первая
 * доступная) реализовано здесь, а не в аксессорах модели: аксессор видит базу
 * лишь для всей таблицы переводов — предел `with()` теряет последний случай,
 * если в базе нет ни текущей, ни fallback-локали. Ключи локалей предзагружены,
 * поэтому три прохода — без запросов к БД.
 */
final class EloquentLessonTypeQuery implements LessonTypeQueryInterface
{
    public function allOrdered(): array
    {
        return LessonType::query()
            ->with(['translations' => fn ($q) => $q->whereIn('locale', self::locales())])
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (LessonType $type) => $this->toOption($type))
            ->all();
    }

    public function optionByCode(string $code): ?LessonTypeOption
    {
        $type = LessonType::query()
            ->with(['translations' => fn ($q) => $q->whereIn('locale', self::locales())])
            ->where('code', $code)
            ->first();

        return $type === null ? null : $this->toOption($type);
    }

    private function toOption(LessonType $type): LessonTypeOption
    {
        $locale = app()->getLocale();
        $fallback = (string) config('app.fallback_locale', 'en');

        $translation = $type->translations->firstWhere('locale', $locale)
            ?? $type->translations->firstWhere('locale', $fallback)
            ?? $type->translations->first();

        return new LessonTypeOption(
            id: (int) $type->id,
            code: $type->code,
            title: $translation?->title,
            description: $translation?->description,
            color: $type->color,
            sort: (int) $type->sort,
            nextLessonThreshold: (float) $type->next_lesson_threshold,
            timeGap: (int) $type->time_gap,
        );
    }

    /**
     * Локали, которые вообще нужны в ответе: текущая, fallback и `ru` —
     * запасной «последний шанс», чтобы случай «ни текущей, ни fallback» не
     * остался без перевода при пустой паре выше.
     *
     * @return list<string>
     */
    private static function locales(): array
    {
        return array_values(array_unique([
            app()->getLocale(),
            (string) config('app.fallback_locale', 'en'),
            'ru',
        ]));
    }
}
