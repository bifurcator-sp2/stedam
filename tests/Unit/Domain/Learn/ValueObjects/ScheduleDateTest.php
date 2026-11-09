<?php

declare(strict_types=1);

use App\Domain\Learn\ValueObjects\ScheduleDate;

describe('isPast', function () {
    it('returns true for a date in the past', function () {
        $date = new ScheduleDate(new DateTimeImmutable('2025-01-01 09:00:00 UTC'));
        expect($date->isPast(new DateTimeImmutable('2025-01-01 10:00:00 UTC')))->toBeTrue();
    });

    it('returns false for a date in the future', function () {
        $date = new ScheduleDate(new DateTimeImmutable('2025-01-01 11:00:00 UTC'));
        expect($date->isPast(new DateTimeImmutable('2025-01-01 10:00:00 UTC')))->toBeFalse();
    });
});

describe('isOnLocalDay', function () {
    it('matches the same day in a single timezone', function () {
        $date = new ScheduleDate(new DateTimeImmutable('2025-01-15 09:00:00 UTC'));
        $moment = new DateTimeImmutable('2025-01-15 22:00:00 UTC');

        expect($date->isOnLocalDay($moment, domainZone('UTC')))->toBeTrue();
    });

    it('uses local day, not UTC day', function () {
        // 22:30 UTC 15 января = 01:30 МСК 16 января
        $date = new ScheduleDate(new DateTimeImmutable('2025-01-15 22:30:00 UTC'));
        // 20:00 UTC 15 января = 23:00 МСК 15 января
        $moment = new DateTimeImmutable('2025-01-15 20:00:00 UTC');

        // В UTC — один день (15 января), в МСК — разные (16 vs 15).
        expect($date->isOnLocalDay($moment, domainZone('UTC')))->toBeTrue()
            ->and($date->isOnLocalDay($moment, domainZone('Europe/Moscow')))->toBeFalse();
    });
});

describe('isBeforeLocalDay / isAfterLocalDay', function () {
    it('detects before / after across timezones', function () {
        $date = new ScheduleDate(new DateTimeImmutable('2025-01-10 12:00:00 UTC'));
        $moment = new DateTimeImmutable('2025-01-15 12:00:00 UTC');

        expect($date->isBeforeLocalDay($moment, domainZone('UTC')))->toBeTrue()
            ->and($date->isAfterLocalDay($moment, domainZone('UTC')))->toBeFalse()
            ->and($date->isOnLocalDay($moment, domainZone('UTC')))->toBeFalse();
    });
});

it('compares by value', function () {
    $a = new ScheduleDate(new DateTimeImmutable('2025-01-15 09:00:00 UTC'));
    $b = new ScheduleDate(new DateTimeImmutable('2025-01-15 09:00:00 UTC'));
    $c = new ScheduleDate(new DateTimeImmutable('2025-01-15 10:00:00 UTC'));

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});
