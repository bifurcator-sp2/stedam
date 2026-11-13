<?php

declare(strict_types=1);

namespace App\Domain\Course\ValueObjects;

use App\Domain\Course\Exceptions\InvalidTimeGap;
use DateInterval;

/**
 * Разрыв между планом предыдущего урока и планом следующего.
 *
 * Хранится как целое число ЧАСОВ (см. §14.3 спеки): "time_gap integer" в БД,
 * применяется к планируемому уроку. Дробные разрывы не поддерживаются.
 *
 *   schedule_date(L_next) = schedule_date(L_prev) + time_gap(L_next)
 */
final readonly class TimeGap
{
    public function __construct(public int $hours)
    {
        if ($hours < 0) {
            throw new InvalidTimeGap("TimeGap hours must be >= 0, got {$hours}");
        }
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function default(): self
    {
        return new self((int) config('course.lessons.time_gap', 2));
    }

    public function toInterval(): DateInterval
    {
        return new DateInterval('PT' . $this->hours . 'H');
    }

    public function equals(self $other): bool
    {
        return $this->hours === $other->hours;
    }

    public function __toString(): string
    {
        return $this->hours . 'h';
    }
}
