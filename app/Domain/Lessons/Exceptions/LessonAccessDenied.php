<?php

declare(strict_types=1);

namespace App\Domain\Lessons\Exceptions;

use DomainException;

/**
 * Действие над уроком запрещено для этого пользователя.
 */
final class LessonAccessDenied extends DomainException
{
    public static function notOwner(int $lessonId, int $userId): self
    {
        return new self("User {$userId} is not allowed to modify lesson {$lessonId}");
    }
}
