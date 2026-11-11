<?php

declare(strict_types=1);

namespace App\Domain\Lessons\Exceptions;

use DomainException;

/**
 * Попытка отредактировать содержимое опубликованного урока.
 *
 * Опубликованный урок редактировать нельзя; сначала нужно снять его
 * с публикации (published = false).
 */
final class LessonIsPublishedEdit extends DomainException
{
    public static function forLesson(int $lessonId): self
    {
        return new self(
            "Нельзя редактировать опубликованный урок #{$lessonId}. "
            . 'Сначала снимите его с публикации.'
        );
    }
}
