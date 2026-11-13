<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

/**
 * Курс не найден на уровне домена (например, при поиске темы в агрегате).
 * Application-слой использует своё CourseNotFound для 404.
 */
final class CourseNotFound extends DomainException
{
    public static function withId(int $courseId): self
    {
        return new self("Course {$courseId} not found");
    }
}
