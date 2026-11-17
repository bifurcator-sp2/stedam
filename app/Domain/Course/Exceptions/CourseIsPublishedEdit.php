<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

/**
 * Попытка отредактировать содержимое опубликованного курса.
 *
 * Опубликованный курс редактировать нельзя; сначала нужно снять его
 * с публикации (published = false).
 */
final class CourseIsPublishedEdit extends DomainException
{
    public static function forCourse(int $courseId): self
    {
        return new self(
            "Нельзя редактировать опубликованный курс #{$courseId}. "
            . 'Сначала снимите его с публикации.'
        );
    }
}
