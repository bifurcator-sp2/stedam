<?php

declare(strict_types=1);

namespace App\Application\Course\Actions;

use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Course\Ports\CourseAssignmentRepositoryInterface;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Course\Services\CourseGradeRecalculator;
use App\Domain\Course\Entities\CourseAssignment;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId;

/**
 * Пересчёт completion/grade по одному ученику.
 *
 * Вызывается:
 *  - вручную (кнопка «пересчитать» в админке);
 *  - слушателем события «урок оценён» (через Application-слой Learn).
 *
 * Для пересчёта всех учеников курса — RecalculateCourseForAllAction.
 */
final readonly class RecalculateCourseAction
{
    public function __construct(
        private CourseRepositoryInterface $courses,
        private CourseAssignmentRepositoryInterface $assignments,
        private CourseGradeRecalculator $recalculator,
    ) {}

    public function execute(
        CourseViewerContext $viewer,
        CourseId $courseId,
        UserId $studentId,
    ): CourseAssignment {
        $course = $this->courses->findById($courseId)
            ?? throw CourseNotFound::withId($courseId->value);

        $assignment = $this->assignments->findByCourseAndStudent($courseId, $studentId)
            ?? throw CourseNotFound::withId($courseId->value);

        if (
            !$viewer->canEdit($course->userId())
            && !$viewer->canSeeProgressOf($studentId)
        ) {
            throw CourseAccessDenied::progressOfStudent($studentId->value);
        }

        return $this->assignments->save(
            $this->recalculator->recalculate($course, $assignment),
        );
    }
}
