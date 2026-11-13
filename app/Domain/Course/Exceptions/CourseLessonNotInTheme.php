<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

final class CourseLessonNotInTheme extends DomainException
{
    public static function withIds(int $themeId, int $lessonId): self
    {
        return new self("Lesson {$lessonId} is not in theme {$themeId}");
    }
}
