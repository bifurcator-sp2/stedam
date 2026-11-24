<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

use DomainException;

/**
 * База доменных ошибок контекста RequestedAction.
 *
 * Всё, что наследует DomainException, НЕ переводит агрегат в error:
 * статус не меняется, ActionError не эмитится, наружу уходит 4xx
 * (раздел «Сбор ошибок» в разделе 1).
 */
class RequestedActionDomainException extends DomainException {}
