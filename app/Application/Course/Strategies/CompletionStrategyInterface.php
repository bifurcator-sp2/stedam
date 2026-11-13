<?php

declare(strict_types=1);

namespace App\Application\Course\Strategies;

use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\CourseCompletion;

/**
 * Стратегия пройденности курса (§7.2).
 *
 * Дефолт: gradedLessons / totalLessons. Альтернатива — с учётом допуска
 * (нельзя пройти курс, пока есть заблокированные уроки). Подключается через
 * config('course.strategies.completion').
 */
interface CompletionStrategyInterface
{
    /**
     * @param array<int, bool> $gradedLessons ключ — lesson_id, значение — оценён ли
     */
    public function completion(Course $course, array $gradedLessons): CourseCompletion;
}
