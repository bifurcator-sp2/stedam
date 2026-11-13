<?php

declare(strict_types=1);

namespace App\Listeners\Course;

use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Course\Ports\LearnPortInterface;
use App\Domain\Course\Events\CourseAssignmentRemoved;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * §12.3: при снятии назначения — удалить НЕ НАЧАТЫЕ прохождения уроков.
 *
 * Начатые (in_progress) и завершённые (done/graded) остаются: ученик мог
 * сдать работу, и её нельзя стирать молча.
 */
final class UnenrollOnAssignmentRemoved implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        private CourseRepositoryInterface $courses,
        private LearnPortInterface $learn,
    ) {}

    public function handle(CourseAssignmentRemoved $event): void
    {
        $course = $this->courses->findById($event->courseId);

        if ($course === null) {
            Log::warning('CourseAssignmentRemoved: course not found', [
                'courseId' => $event->courseId->value,
                'studentId' => $event->studentId->value,
            ]);

            return;
        }

        foreach ($course->flatLessons() as $entry) {
            $this->learn->deleteUnstarted($event->studentId, $entry['lesson']->lessonId());
        }
    }
}
