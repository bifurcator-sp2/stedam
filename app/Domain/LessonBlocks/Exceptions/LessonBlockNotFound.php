<?php

declare(strict_types=1);

namespace App\Domain\LessonBlocks\Exceptions;

use DomainException;

/**
 * Блок урока/задание не найдено.
 */
final class LessonBlockNotFound extends DomainException
{
    public static function withId(int $id): self
    {
        return new self("LessonBlock {$id} not found");
    }
}
