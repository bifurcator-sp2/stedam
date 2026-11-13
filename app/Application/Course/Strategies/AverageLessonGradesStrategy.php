<?php

declare(strict_types=1);

namespace App\Application\Course\Strategies;

use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\CourseGrade;

/**
 * Оценка курса = среднее оценок оценённых уроков.
 *
 * Неоценённые уроки не учитываются (не тянут среднее к нулю) — иначе
 * ученик, сдавший первый урок на 1.0, получил бы 0.1 из-за девяти
 * несданных. Сколько пройдено — отвечает CompletionStrategy.
 *
 * Уроки с оценкой 0 считаются оценёнными и участвуют: сдал на 0 —
 * это результат, а не «ещё не сдавал».
 */
final readonly class AverageLessonGradesStrategy implements GradeStrategyInterface
{
    public function grade(Course $course, array $lessonGrades): CourseGrade
    {
        $sum = 0.0;
        $count = 0;

        foreach ($lessonGrades as $grade) {
            if ($grade === null) {
                continue;
            }

            $sum += $grade;
            $count++;
        }

        return $count === 0 ? CourseGrade::zero() : new CourseGrade($sum / $count);
    }
}
