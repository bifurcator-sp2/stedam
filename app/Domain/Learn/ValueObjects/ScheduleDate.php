<?php

declare(strict_types=1);

namespace App\Domain\Learn\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;

final readonly class ScheduleDate
{
    public function __construct(public DateTimeImmutable $value) {}

    public function isPast(DateTimeImmutable $now): bool
    {
        return $this->value < $now;
    }

    public function isOnLocalDay(DateTimeImmutable $moment, DateTimeZone $tz): bool
    {
        return $this->localDay($tz) === $moment->setTimezone($tz)->format('Y-m-d');
    }

    public function isBeforeLocalDay(DateTimeImmutable $moment, DateTimeZone $tz): bool
    {
        return $this->localDay($tz) < $moment->setTimezone($tz)->format('Y-m-d');
    }

    public function isAfterLocalDay(DateTimeImmutable $moment, DateTimeZone $tz): bool
    {
        return $this->localDay($tz) > $moment->setTimezone($tz)->format('Y-m-d');
    }

    public function equals(self $other): bool
    {
        return $this->value == $other->value;
    }

    private function localDay(DateTimeZone $tz): string
    {
        return $this->value->setTimezone($tz)->format('Y-m-d');
    }
}
