<?php

declare(strict_types=1);

namespace App\Application\Course\Strategies;

use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\CourseCompletion;

/**
 * Пройденность = число оценённых уроков / общее число уроков.
 *
 * Курс без уроков считается непройденным (0.0), а не пройденным:
 * «пройти» пустой курс нельзя.
 */
final readonly class LessonCountCompletionStrategy implements CompletionStrategyInterface
{
    public function completion(Course $course, array $gradedLessons): CourseCompletion
    {
        $graded = 0;

        foreach ($gradedLessons as $isGraded) {
            if ($isGraded === true) {
                $graded++;
            }
        }

        return CourseCompletion::ratio($graded, $course->countLessons());
    }
}
