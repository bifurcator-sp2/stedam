<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use InvalidArgumentException;

/**
 * Узел дерева таксономии (ветка программы, к которой привязан курс).
 */
final readonly class TaxonomyId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("TaxonomyId must be positive, got {$value}");
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
