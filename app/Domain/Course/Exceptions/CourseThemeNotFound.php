<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

final class CourseThemeNotFound extends DomainException
{
    public static function withId(int $themeId): self
    {
        return new self("Course theme {$themeId} not found");
    }
}
