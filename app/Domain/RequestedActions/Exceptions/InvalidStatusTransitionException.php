<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

use App\Domain\RequestedActions\Enums\RequestedActionStatus;

/**
 * Недопустимый переход статуса — нарушение инварианта агрегата.
 * Доменная ошибка (4xx), ActionError не эмитится.
 */
final class InvalidStatusTransitionException extends RequestedActionDomainException
{
    public static function fromTo(RequestedActionStatus $from, RequestedActionStatus $to): self
    {
        return new self("Cannot transition RequestedAction from {$from->value} to {$to->value}");
    }
}
