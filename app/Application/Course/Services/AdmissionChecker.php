<?php

declare(strict_types=1);

namespace App\Application\Course\Services;

use App\Application\Course\Ports\LearnPortInterface;
use App\Application\Course\Ports\LessonReaderInterface;
use App\Application\Course\Strategies\AdmissionStrategyInterface;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\UserId;

/**
 * Допуск ученика к уроку — сводит вместе источники:
 *
 *  - домен: позиция урока в курсе (Course::flatLessons) и пороги;
 *  - порт Learn: оценка предыдущего урока данного ученика;
 *  - порт Lessons: код типа урока (lessons.type) и порог типа из
 *    справочника lesson_types.
 *
 * Тип урока в связке тема→урок не хранится: он берётся у самого урока.
 *
 * Только чтение, без побочных эффектов.
 */
final readonly class AdmissionChecker
{
    /**
     * @param array<string, float|null> $typeThresholdsByCode код типа => порог
     */
    public function __construct(
        private LearnPortInterface $learn,
        private AdmissionStrategyInterface $strategy,
        private LessonReaderInterface $lessons,
        private array $typeThresholdsByCode = [],
    ) {}

    public function isAdmitted(UserId $studentId, Course $course, LessonId $lessonId): bool
    {
        return $this->strategy->isAdmitted(
            course: $course,
            lessonId: $lessonId,
            typeThreshold: $this->typeThresholdFor($course, $lessonId),
            previousGrade: $this->previousGradeFor($studentId, $course, $lessonId),
        );
    }

    /**
     * Допуск для всех уроков курса (для рендера списка с замками).
     *
     * @param list<LessonId> $lessonIds
     * @return array<int, bool> lesson_id => допущен
     */
    public function admissionMap(UserId $studentId, Course $course, array $lessonIds): array
    {
        $result = [];

        foreach ($lessonIds as $lessonId) {
            $result[$lessonId->value] = $this->isAdmitted($studentId, $course, $lessonId);
        }

        return $result;
    }

    private function previousGradeFor(UserId $studentId, Course $course, LessonId $lessonId): ?float
    {
        $previous = null;

        foreach ($course->flatLessons() as $entry) {
            if ($entry['lesson']->lessonId()->equals($lessonId)) {
                break;
            }

            $previous = $entry['lesson']->lessonId();
        }

        return $previous === null ? null : $this->learn->gradeOf($studentId, $previous);
    }

    private function typeThresholdFor(Course $course, LessonId $lessonId): ?float
    {
        if ($course->findLesson($lessonId) === null) {
            return null;
        }

        $code = $this->lessons->typeCodesOf([$lessonId])[$lessonId->value] ?? null;

        return $code === null ? null : ($this->typeThresholdsByCode[$code] ?? null);
    }
}
