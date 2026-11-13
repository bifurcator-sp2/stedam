<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use App\Domain\Course\Exceptions\InvalidLocale;

/**
 * Язык курса. Формат: ISO 639-1 (2 буквы) или с региональным суффиксом
 * (ru-RU, en-US). БД: courses.locale varchar(8).
 *
 * Нарушение — InvalidLocale: HTTP-слой переводит его в 422.
 */
final readonly class Locale
{
    private const MAX_LENGTH = 8;

    public function __construct(public string $value)
    {
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidLocale(sprintf(
                'Locale must be at most %d characters, got %d',
                self::MAX_LENGTH,
                mb_strlen($value),
            ));
        }

        if (preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $value) !== 1) {
            throw new InvalidLocale(
                "Locale must look like 'xx' or 'xx-XX', got '{$value}'"
            );
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
