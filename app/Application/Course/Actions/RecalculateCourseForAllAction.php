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

/**
 * Пересчёт оценок ВСЕХ назначений курса.
 *
 * Нужен после изменения состава курса: добавили/убрали урок — у всех
 * учеников сдвинулись completion и, возможно, grade.
 *
 * Возвращает число пересчитанных назначений (для лога/теста).
 */
final readonly class RecalculateCourseForAllAction
{
    public function __construct(
        private CourseRepositoryInterface $courses,
        private CourseAssignmentRepositoryInterface $assignments,
        private CourseGradeRecalculator $recalculator,
    ) {}

    public function execute(CourseViewerContext $viewer, CourseId $courseId): int
    {
        $course = $this->courses->findById($courseId)
            ?? throw CourseNotFound::withId($courseId->value);

        if (!$viewer->canEdit($course->userId())) {
            throw CourseAccessDenied::edit($courseId->value);
        }

        $count = 0;

        /** @var CourseAssignment $assignment */
        foreach ($this->assignments->listByCourse($courseId) as $assignment) {
            $this->assignments->save(
                $this->recalculator->recalculate($course, $assignment),
            );
            $count++;
        }

        return $count;
    }
}
