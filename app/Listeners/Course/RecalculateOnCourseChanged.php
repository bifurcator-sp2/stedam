<?php

declare(strict_types=1);

namespace App\Listeners\Course;

use App\Application\Course\Ports\CourseAssignmentRepositoryInterface;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Course\Services\CourseGradeRecalculator;
use App\Domain\Course\Events\CourseChanged;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Пересчёт completion/grade всех учеников после изменения состава курса.
 *
 * Пользователь добавил/убрал урок — у всех назначенных сдвинулась
 * пройденность (а иногда и оценка). Делаем в очереди: назначений может быть
 * сотни, а запрос редактирования должен отвечать сразу.
 *
 * Без этого слушателя статистика курса «залипает» на старых значениях до
 * ручного пересчёта.
 */
final class RecalculateOnCourseChanged implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private CourseRepositoryInterface $courses,
        private CourseAssignmentRepositoryInterface $assignments,
        private CourseGradeRecalculator $recalculator,
    ) {}

    public function handle(CourseChanged $event): void
    {
        $course = $this->courses->findById($event->courseId);

        if ($course === null) {
            Log::warning('CourseChanged: course not found', [
                'courseId' => $event->courseId->value,
            ]);

            return;
        }

        foreach ($this->assignments->listByCourse($event->courseId) as $assignment) {
            $this->assignments->save(
                $this->recalculator->recalculate($course, $assignment),
            );
        }
    }
}
