<?php

declare(strict_types=1);

namespace App\Application\Lessons\Exceptions;

use DomainException;

/**
 * Отказ в доступе на уровне Application (когда нельзя бросить
 * Domain\Lessons\Exceptions\LessonAccessDenied — например, когда
 * пока не загружен урок, но права известны по id владельца).
 */
final class LessonAccessDeniedError extends DomainException
{
    public static function notOwner(int $lessonId, int $userId): self
    {
        return new self("User {$userId} is not allowed to modify lesson {$lessonId}");
    }
}
