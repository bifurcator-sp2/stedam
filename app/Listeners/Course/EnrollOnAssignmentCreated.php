<?php

declare(strict_types=1);

namespace App\Listeners\Course;

use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Course\Ports\LearnPortInterface;
use App\Domain\Course\Events\CourseAssignmentCreated;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * §12.2: при создании назначения — создать прохождения всех уроков курса.
 *
 * ShouldQueueAfterCommit: AssignCourseAction публикует событие ВНУТРИ
 * транзакции, поэтому без «after commit» слушатель мог бы улететь в очередь,
 * пока запись ещё не зафиксирована (и не откатится ли она — неизвестно).
 * Очередь — чтобы назначение курса из 200 уроков не вешало запрос.
 *
 * Идемпотентность: LearnPort::enrollLesson не создаёт дубль активного
 * прохождения, поэтому повторная доставка безопасна.
 */
final class EnrollOnAssignmentCreated implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        private CourseRepositoryInterface $courses,
        private LearnPortInterface $learn,
    ) {}
    public function handle(CourseAssignmentCreated $event): void
    {
        $course = $this->courses->findById($event->courseId);

        if ($course === null) {
            // Курс удалён между публикацией и обработкой — делать нечего.
            Log::warning('CourseAssignmentCreated: course not found', [
                'courseId' => $event->courseId->value,
                'studentId' => $event->studentId->value,
            ]);

            return;
        }

        foreach ($course->flatLessons() as $entry) {
            $this->learn->enrollLesson($event->studentId, $entry['lesson']->lessonId());
        }
    }
}
