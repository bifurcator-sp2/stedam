<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

/**
 * Урок уже присутствует в этом курсе.
 *
 * Домен строже БД: в БД UNIQUE только (theme_id, lesson_id), т.е. технически
 * один lesson_id можно засунуть в две темы. Домен это запрещает — урок в
 * двух темах одного курса почти наверняка ошибка (прогресс ученика
 * дублировался бы, flat-порядок становился неоднозначным).
 */
final class CourseLessonAlreadyInCourse extends DomainException
{
    public static function forCourse(int $courseId, int $lessonId): self
    {
        return new self(
            "Lesson {$lessonId} is already present in course {$courseId}"
        );
    }
}
