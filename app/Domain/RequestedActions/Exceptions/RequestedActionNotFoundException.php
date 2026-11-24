<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

/**
 * Агрегат RequestedAction не найден (404).
 */
final class RequestedActionNotFoundException extends RequestedActionDomainException
{
    public static function withId(int $id): self
    {
        return new self("RequestedAction {$id} not found");
    }
}
