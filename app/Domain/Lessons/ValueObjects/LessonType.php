<?php

declare(strict_types=1);

namespace App\Domain\Lessons\ValueObjects;

use InvalidArgumentException;

/**
 * Тип урока — код из справочника `lesson_types.code`
 * ('class_work', 'home_work', 'ind_work', 'control_work').
 *
 * У урока тип обязателен: без него курс не может посчитать порог
 * допуска к следующему уроку (lesson_types.next_lesson_threshold).
 * Тип меняется только у неопубликованного урока (см. Application-слой).
 *
 * Допустимые коды — открытый справочник в БД, поэтому VO проверяет
 * только форму кода (snake_case, 1..32 символа), а не membership:
 * валидация «код существует» — задача HTTP/справочника.
 */
final readonly class LessonType
{
    /** Тип по умолчанию для нового урока. */
    public const DEFAULT = 'class_work';

    public string $value;

    public function __construct(string $value)
    {
        $code = trim($value);

        if ($code === '') {
            throw new InvalidArgumentException('LessonType must not be empty');
        }

        if (strlen($code) > 32) {
            throw new InvalidArgumentException(
                sprintf('LessonType must be at most 32 chars, got %d', strlen($code))
            );
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $code) !== 1) {
            throw new InvalidArgumentException(
                "LessonType must be snake_case ([a-z][a-z0-9_]*), got '{$code}'"
            );
        }

        $this->value = $code;
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
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
