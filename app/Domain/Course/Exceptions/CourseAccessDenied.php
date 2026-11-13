<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

/**
 * Доменный отказ в действии (базовая проверка, не зависящая от смотрящего).
 * Например, попытка изменить системный курс обычным пользователем
 * или снять назначение, созданное не тобой.
 *
 * Проверки ролей/скоупа смотрящего — в Application (CourseViewerContext).
 */
final class CourseAccessDenied extends DomainException
{
    public static function systemCourse(int $courseId): self
    {
        return new self("System course {$courseId} cannot be modified by a user");
    }
}
