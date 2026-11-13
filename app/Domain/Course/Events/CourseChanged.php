<?php

declare(strict_types=1);

namespace App\Domain\Course\Events;

use App\Domain\Course\ValueObjects\CourseId;

/**
 * Курс изменён: шапка, темы или состав уроков.
 *
 * Слушатель RecalculateOnCourseChanged пересчитывает completion/grade
 * всех назначений — иначе статистика «залипает» после правки состава.
 *
 * Публикуется Application-слоем (EditCourse*Action) после успешного save().
 */
final readonly class CourseChanged
{
    public function __construct(
        public CourseId $courseId,
    ) {}
}
