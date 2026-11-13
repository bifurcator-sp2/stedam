<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use InvalidArgumentException;

/**
 * Название курса / темы: непустая строка без пробелов по краям, до 255 символов
 * (ограничение БД courses.title varchar(255)).
 */
final readonly class CourseTitle
{
    private const MAX_LENGTH = 255;

    public function __construct(public string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException('CourseTitle must not be empty');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'CourseTitle must be at most %d characters, got %d',
                self::MAX_LENGTH,
                mb_strlen($trimmed),
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
