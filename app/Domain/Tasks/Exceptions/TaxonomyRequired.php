<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Exceptions;

use DomainException;

/**
 * Обычное задание обязано быть привязано хотя бы к одному разделу таксономии.
 */
final class TaxonomyRequired extends DomainException
{
    public static function forTask(): self
    {
        return new self('Обычное задание должно быть привязано хотя бы к одному разделу таксономии.');
    }
}
