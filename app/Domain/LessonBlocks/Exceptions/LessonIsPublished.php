<?php

declare(strict_types=1);

namespace App\Domain\LessonBlocks\Exceptions;

use DomainException;

/**
 * Попытка изменить структуру опубликованного урока.
 *
 * Публикация фиксирует содержимое: правки требуют снятия с публикации.
 */
final class LessonIsPublished extends DomainException
{
    public static function forLesson(int $lessonId): self
    {
        return new self(
            "Нельзя изменять блоки опубликованного урока #{$lessonId}. "
            . 'Сначала снимите его с публикации.'
        );
    }
}
