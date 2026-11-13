<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

/**
 * Описание курса. Может быть пустым (в БД DEFAULT '').
 *
 * Хранится как есть: сохраняем авторские переносы строк и внутренние пробелы.
 */
final readonly class CourseDescription
{
    public function __construct(public string $value = '') {}

    public static function empty(): self
    {
        return new self('');
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
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
