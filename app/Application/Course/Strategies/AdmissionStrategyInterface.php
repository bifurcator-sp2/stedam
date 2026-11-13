<?php

declare(strict_types=1);

namespace App\Application\Course\Strategies;

use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\NextLessonThreshold;

/**
 * Стратегия допуска к уроку (§7.3).
 *
 * Порог по умолчанию (см. §8.2):
 *   threshold(L_next) = max(course.next_lesson_threshold,
 *                           lesson_type.next_lesson_threshold)
 *
 * Стратегия умеет либо только ответить «какой порог», либо полностью
 * решить «допущен ли» — реализация решает, что ей проще. Допуск для
 * ученика считает AdmissionChecker в Application (он ходит в Learn-порт).
 */
interface AdmissionStrategyInterface
{
    /**
     * Порог для урока L.
     *
     * @param float|null $typeThreshold порог типа урока (0..1) или null
     */
    public function thresholdFor(Course $course, LessonId $lessonId, ?float $typeThreshold): NextLessonThreshold;

    /**
     * Все ли условия для прохождения урока выполнены.
     *
     * @param float|null $previousGrade оценка предыдущего урока курса (0..1)
     */
    public function isAdmitted(
        Course $course,
        LessonId $lessonId,
        ?float $typeThreshold,
        ?float $previousGrade,
    ): bool;
}
