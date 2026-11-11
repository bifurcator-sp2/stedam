<?php

declare(strict_types=1);

namespace App\Domain\Lessons\ValueObjects;

use InvalidArgumentException;

/**
 * Идентификатор узла дерева таксономии.
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
