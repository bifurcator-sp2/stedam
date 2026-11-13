<?php

declare(strict_types=1);

namespace App\Application\Course\Exceptions;

use RuntimeException;

/**
 * Курс не найден на уровне Application (404).
 */
final class CourseNotFound extends RuntimeException
{
    public static function withId(int $courseId): self
    {
        return new self("Course {$courseId} not found");
    }
}
