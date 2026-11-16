<?php

declare(strict_types=1);

namespace App\Application\Course\Strategies;

use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\CourseGrade;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\NextLessonThreshold;

/**
 * Последовательный допуск (§8).
 *
 *  - Первый урок курса открыт всегда (нет предыдущего — нет требования).
 *  - Для L_k оценка предыдущего L_{k-1} должна СТРОГО превышать порог.
 *  - Порог = max(course.next_lesson_threshold, type.next_lesson_threshold):
 *    курс может ужесточить требование типа, но не ослабить.
 *  - Уроки вне курса (lessonId не найден) — не допускаем: это ошибка
 *    вызывающего, а не повод открыть доступ.
 */
final readonly class SequentialAdmissionStrategy implements AdmissionStrategyInterface
{
    /**
     * Порог для L_k.
     *
     * Базовый порог берётся из типа урока и масштабируется множителем курса:
     *   base * threshold_multipler.
     * Курс учителя (course.next_lesson_threshold) может дополнительно
     * ужесточить результат — max(course, base * multiplier).
     *
     * Тип урока не задан — курс работает на своём пороге (множителю нечего
     * масштабировать).
     */
    public function thresholdFor(
        Course $course,
        LessonId $lessonId,
        ?float $typeThreshold,
    ): NextLessonThreshold {
        $courseThreshold = $course->nextLessonThreshold();

        if ($typeThreshold === null) {
            return $courseThreshold;
        }

        $scaled = $course->thresholdMultiplier()->multiply(new NextLessonThreshold($typeThreshold));

        return $courseThreshold->max($this->clamp($scaled));
    }

    /**
     * Доля не может превышать 1.0: VO NextLessonThreshold ограничен (0..1].
     * Множитель > 1 поднимает требование к типу, но не выше «всё сдано».
     */
    private function clamp(float $value): NextLessonThreshold
    {
        return new NextLessonThreshold(min($value, 1.0));
    }

    public function isAdmitted(
        Course $course,
        LessonId $lessonId,
        ?float $typeThreshold,
        ?float $previousGrade,
    ): bool {
        $position = $this->positionInCourse($course, $lessonId);

        if ($position === null) {
            return false;
        }

        // Первый урок — всегда открыт.
        if ($position === 0) {
            return true;
        }

        // Предыдущий урок ещё не оценён.
        if ($previousGrade === null) {
            return false;
        }

        $threshold = $this->thresholdFor($course, $lessonId, $typeThreshold);

        return $threshold->isSatisfiedBy(new CourseGrade($previousGrade));
    }

    /**
     * Позиция урока в плоском порядке курса (0-based) или null.
     */
    private function positionInCourse(Course $course, LessonId $lessonId): ?int
    {
        $position = 0;

        foreach ($course->flatLessons() as $entry) {
            if ($entry['lesson']->lessonId()->equals($lessonId)) {
                return $position;
            }

            $position++;
        }

        return null;
    }
}
