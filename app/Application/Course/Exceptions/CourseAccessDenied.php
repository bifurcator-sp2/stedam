<?php

declare(strict_types=1);

namespace App\Application\Course\Exceptions;

use RuntimeException;

/**
 * Отказ по правам смотрящего (403).
 */
final class CourseAccessDenied extends RuntimeException
{
    public static function edit(int $courseId): self
    {
        return new self("You are not allowed to edit course {$courseId}");
    }

    public static function assign(int $courseId): self
    {
        return new self("You are not allowed to assign course {$courseId}");
    }

    public static function view(int $courseId): self
    {
        return new self("You are not allowed to view course {$courseId}");
    }

    public static function progressOfStudent(int $studentId): self
    {
        return new self("You are not allowed to view progress of student {$studentId}");
    }
}
