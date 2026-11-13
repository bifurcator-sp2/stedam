<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

final class CourseAlreadyAssigned extends DomainException
{
    public static function forPair(int $courseId, int $studentId): self
    {
        return new self("Course {$courseId} is already assigned to student {$studentId}");
    }
}
