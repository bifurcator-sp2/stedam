<?php

declare(strict_types=1);

namespace App\Domain\Course\Events;

use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId;

/**
 * Курс назначен ученику.
 *
 * Публикуется Application-слоем после успешного сохранения назначения.
 * Слушатель EnrollOnAssignmentCreated создаёт прохождения уроков.
 */
final readonly class CourseAssignmentCreated
{
    public function __construct(
        public CourseId $courseId,
        public UserId $studentId,
        public UserId $assignedBy,
    ) {}
}
