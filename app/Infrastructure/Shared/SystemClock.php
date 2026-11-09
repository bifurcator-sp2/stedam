<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared;

use App\Application\Shared\Clock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemClock implements Clock
{
    private DateTimeZone $tz;

    public function __construct(?DateTimeZone $tz = null)
    {
        $this->tz = $tz ?? new DateTimeZone('UTC');
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->tz);
    }
}
