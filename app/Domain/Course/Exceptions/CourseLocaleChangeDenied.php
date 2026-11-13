<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

/**
 * Смена локали курса запрещена, если в курсе уже есть уроки: смена языка
 * программы ломает уже выданные задания (ученик видит уроки на другом языке,
 * чем ожидалось).
 */
final class CourseLocaleChangeDenied extends DomainException
{
    public static function hasLessons(int $lessonCount): self
    {
        return new self(
            "Cannot change course locale while course has {$lessonCount} lesson(s)"
        );
    }
}
