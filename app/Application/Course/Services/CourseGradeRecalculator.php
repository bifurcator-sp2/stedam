<?php

declare(strict_types=1);

namespace App\Application\Course\Services;

use App\Application\Course\Ports\LearnPortInterface;
use App\Application\Course\Strategies\CompletionStrategyInterface;
use App\Application\Course\Strategies\GradeStrategyInterface;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Entities\CourseAssignment;
use App\Domain\Course\ValueObjects\LessonId;

/**
 * Пересчёт оценок назначения по курсу.
 *
 * Источник данных — порт Learn (что ученик уже сдал). Стратегии решают,
 * как из этого получить grade/completion.
 *
 * Не пишет в БД: возвращает обновлённый агрегат, сохраняет вызывающий
 * (RecalculateCourseAction или слушатель события).
 */
final readonly class CourseGradeRecalculator
{
    public function __construct(
        private LearnPortInterface $learn,
        private GradeStrategyInterface $gradeStrategy,
        private CompletionStrategyInterface $completionStrategy,
    ) {}

    public function recalculate(Course $course, CourseAssignment $assignment): CourseAssignment
    {
        $studentId = $assignment->studentId();
        $lessonIds = $this->lessonIds($course);

        $grades = $this->learn->gradesOf($studentId, $lessonIds);

        $gradedFlags = [];
        foreach ($grades as $lessonId => $grade) {
            $gradedFlags[$lessonId] = $grade !== null;
        }

        return $assignment->updateScores(
            completion: $this->completionStrategy->completion($course, $gradedFlags),
            grade: $this->gradeStrategy->grade($course, $grades),
        );
    }

    /**
     * @return list<LessonId>
     */
    private function lessonIds(Course $course): array
    {
        $ids = [];

        foreach ($course->flatLessons() as $entry) {
            $ids[] = $entry['lesson']->lessonId();
        }

        return $ids;
    }
}
