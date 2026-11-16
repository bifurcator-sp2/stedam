<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use DomainException;

/**
 * Множитель порога курса вне допустимого диапазона: должен быть строго
 * положительным конечным числом.
 */
final class InvalidThresholdMultiplier extends DomainException
{
}
