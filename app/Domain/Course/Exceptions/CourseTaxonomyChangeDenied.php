<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

/**
 * Перенос курса в другую ветку таксономии запрещён, если в курсе уже есть
 * уроки: назначения ученикам ссылаются на конкретные уроки ветки, а смена
 * ветки инвалидирует доступ. Сначала нужно очистить курс.
 */
final class CourseTaxonomyChangeDenied extends DomainException
{
    public static function hasLessons(int $lessonCount): self
    {
        return new self(
            "Cannot change course taxonomy while course has {$lessonCount} lesson(s)"
        );
    }

    /**
     * Уроки курса лежат вне целевой ветки таксономии.
     *
     * @param list<int> $foreignLessonIds
     */
    public static function lessonsOutsideTarget(int $taxonomyId, array $foreignLessonIds): self
    {
        $ids = implode(', ', $foreignLessonIds);

        return new self(
            "Cannot move course to taxonomy {$taxonomyId}: lessons [{$ids}] are outside the target branch"
        );
    }
}
