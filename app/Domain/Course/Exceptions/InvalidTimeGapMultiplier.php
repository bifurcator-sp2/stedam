<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

/**
 * Множитель паузы курса вне допустимого диапазона: должен быть конечным
 * числом в отрезке [0.0, 100.0].
 */
final class InvalidTimeGapMultiplier extends DomainException
{
}
