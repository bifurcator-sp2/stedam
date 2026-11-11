<?php

declare(strict_types=1);

namespace App\Domain\Lessons\ValueObjects;

use InvalidArgumentException;

/**
 * Сложность задания/урока: 0.0 (просто) … 1.0 (сложно).
 *
 * null семантически = «not set»; представляется через ?Complexity.
 */
final readonly class Complexity
{
    public function __construct(public float $value)
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Complexity must be in [0.0, 1.0], got %s', $value)
            );
        }
    }

    public static function fromNullable(?float $value): ?self
    {
        return $value === null ? null : new self($value);
    }

    public function avg(self $other): self
    {
        return new self(($this->value + $other->value) / 2.0);
    }

    public function equals(?self $other): bool
    {
        if ($other === null) {
            return false;
        }

        return abs($this->value - $other->value) < 1e-9;
    }

    /**
     * Среднее нескольких значений; null, если ни одного не задано.
     *
     * @param iterable<?float> $values
     */
    public static function averageOf(iterable $values): ?self
    {
        $sum = 0.0;
        $count = 0;

        foreach ($values as $v) {
            if ($v === null) {
                continue;
            }
            $sum += (float) $v;
            $count++;
        }

        return $count === 0 ? null : new self($sum / $count);
    }
}
