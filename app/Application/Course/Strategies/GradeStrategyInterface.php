<?php

declare(strict_types=1);

namespace App\Application\Course\Strategies;

use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\CourseGrade;

/**
 * Стратегия оценки курса (§7.1).
 *
 * Вход — курс и уже собранные оценки уроков (0..1, null = не оценён).
 * Выход — оценка курса в [0..1]. Стратегия не ходит в БД: данные ей
 * готовит Application-слой (см. RecalculateCourseAction).
 *
 * Альтернативы (среднее, среднее по темам, взвешенное) подключаются
 * через config('course.strategies.grade').
 */
interface GradeStrategyInterface
{
    /**
     * @param array<int, float|null> $lessonGrades ключ — lesson_id
     */
    public function grade(Course $course, array $lessonGrades): CourseGrade;
}
