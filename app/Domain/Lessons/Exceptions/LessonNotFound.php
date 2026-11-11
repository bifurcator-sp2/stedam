<?php

declare(strict_types=1);

namespace App\Domain\Lessons\Exceptions;

use DomainException;

final class LessonNotFound extends DomainException
{
    public static function withId(int $id): self
    {
        return new self("Lesson {$id} not found");
    }
}
