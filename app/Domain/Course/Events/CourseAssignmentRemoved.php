<?php

declare(strict_types=1);

namespace App\Domain\Course\Events;

use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId;

/**
 * Назначение курса снято.
 *
 * Слушатель UnenrollOnAssignmentRemoved удаляет НЕ НАЧАТЫЕ прохождения
 * уроков (§12.3). Начатые и сданные остаются.
 */
final readonly class CourseAssignmentRemoved
{
    public function __construct(
        public CourseId $courseId,
        public UserId $studentId,
    ) {}
}
