<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\ValueObjects;

use InvalidArgumentException;

/**
 * Идентификатор агрегата RequestedAction (BIGSERIAL в `requested_actions`).
 */
final readonly class RequestedActionId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("RequestedActionId must be positive, got {$value}");
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
