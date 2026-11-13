<?php

declare(strict_types=1);

namespace App\Listeners\Course;

use App\Application\Course\Ports\CourseAssignmentRepositoryInterface;
use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Application\Course\Ports\LearnPortInterface;
use App\Application\Course\Services\CourseGradeRecalculator;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\LessonId as CourseLessonId;
use App\Domain\Course\ValueObjects\UserId as CourseUserId;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\Events\LearnStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Пересчёт прогресса курсов, когда ученику выставили оценку за урок (§17.4 п.9).
 *
 * Подписан на LearnStatusChanged из шины Laravel, но реагирует ТОЛЬКО на
 * статус graded: остальные переходы (in_progress, done) на состав курса не
 * влияют — оценка ещё не выставлена.
 *
 * Правило §14.8: слушатель НЕ бросает наружу. Пересчёт идемпотентен,
 * поэтому ошибка логируется, а не ломает оценку учителя. Следующее событие
 * по этому ученику всё пересчитает заново.
 */
final class RecalculateOnLessonGraded implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private CourseRepositoryInterface $courses,
        private CourseAssignmentRepositoryInterface $assignments,
        private CourseGradeRecalculator $recalculator,
    ) {}

    public function handle(LearnStatusChanged $event): void
    {
        if ($event->status !== LearnStatus::Graded) {
            return;
        }

        // Курсы не настроены (нет config/course.php) — контекст не подключён,
        // молча выходим вместо падения BindResolutionException в Learn.
        if (!config()->has('course.contracts')) {
            return;
        }

        // Слушатель в очереди (ShouldQueueAfterCommit): при sync-драйвере
        // его конструктор резолвится прямо в момент dispatch. Если в
        // контейнере нет порта Learn — пересчитать невозможно, логируем
        // и выходим, не ломая оценивание.
        if (!app()->bound(LearnPortInterface::class)) {
            Log::warning('RecalculateOnLessonGraded: LearnPortInterface not bound');

            return;
        }

        try {
            // Ученик мог проходить урок вне курсов — тогда делать нечего.
            $studentId = $this->studentOf($event->learnId->value);

            if ($studentId === null) {
                return;
            }

            foreach ($this->coursesContaining($event->lessonId->value, $studentId) as $courseId) {
                $this->refresh($courseId, $studentId);
            }
        } catch (\Throwable $e) {
            Log::error('RecalculateOnLessonGraded failed', [
                'learnId' => $event->learnId->value,
                'lessonId' => $event->lessonId->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Владелец прохождения. LearnStatusChanged намеренно не несёт userId —
     * это дело контекста Learn — поэтому читаем точечно по learnId.
     */
    private function studentOf(int $learnId): ?int
    {
        $userId = DB::table('learns')->where('id', $learnId)->value('user_id');

        return $userId === null ? null : (int) $userId;
    }

    /**
     * Курсы, которые содержат этот урок и где ученик действительно назначен.
     *
     * Один запрос вместо «все назначения ученика → проверить каждый курс»:
     * назначений у ученика может быть много, а урок входит в единицы курсов.
     *
     * @return list<CourseId>
     */
    private function coursesContaining(int $lessonId, int $studentId): array
    {
        $ids = DB::table('course_assignments as a')
            ->join('course_themes as t', 't.course_id', '=', 'a.course_id')
            ->join('course_theme_lessons as l', 'l.theme_id', '=', 't.id')
            ->where('a.student_id', $studentId)
            ->where('l.lesson_id', $lessonId)
            ->distinct()
            ->pluck('a.course_id')
            ->all();

        return array_map(static fn ($id): CourseId => new CourseId((int) $id), $ids);
    }

    private function refresh(CourseId $courseId, int $studentId): void
    {
        $course = $this->courses->findById($courseId);

        if ($course === null) {
            Log::warning('RecalculateOnLessonGraded: course not found', [
                'courseId' => $courseId->value,
            ]);

            return;
        }

        $assignment = $this->assignments->findByCourseAndStudent(
            $courseId,
            new CourseUserId($studentId),
        );

        if ($assignment === null) {
            return;
        }

        $this->assignments->save($this->recalculator->recalculate($course, $assignment));
    }
}
