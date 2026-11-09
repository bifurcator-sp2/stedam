<?php

declare(strict_types=1);

use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Enums\LearnCategory;
use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\UserId;

// Хелпер: создаёт scheduled с заданной датой
function scheduledAt(string $when): Learn
{
    return Learn::schedule(
        new UserId(1),
        new LessonId(10),
        new ScheduleDate(new DateTimeImmutable($when)),
        domainNow(),
    );
}

// ─── простые статусы ────────────────────────────────────────────

it('categorises created as Assigned', function () {
    $learn = Learn::assign(new UserId(1), new LessonId(10), domainNow());

    expect($learn->isAssigned())->toBeTrue()
        ->and($learn->category(domainNow(), domainZone()))->toBe(LearnCategory::Assigned);
});

it('categorises in_progress as InProgress', function () {
    $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());

    expect($learn->isInProgress())->toBeTrue()
        ->and($learn->category(domainNow(), domainZone()))->toBe(LearnCategory::InProgress);
});

it('categorises done as AwaitingGrade', function () {
    $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
    $learn->markDone([], domainLater());

    expect($learn->isAwaitingGrade())->toBeTrue()
        ->and($learn->category(domainNow(), domainZone()))->toBe(LearnCategory::AwaitingGrade);
});

it('categorises graded as Graded', function () {
    $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
    $learn->markDone([], domainLater());
    $learn->markGraded(new Grade(90), new UserId(2), domainLater());

    expect($learn->isGraded())->toBeTrue()
        ->and($learn->category(domainNow(), domainZone()))->toBe(LearnCategory::Graded);
});

// ─── scheduled: три подкатегории ────────────────────────────────

it('categorises scheduled-for-today as ScheduledToday', function () {
    // 15 января 12:00 МСК = 09:00 UTC
    $learn = scheduledAt('2025-01-15 09:00:00 UTC');
    // тот же день, 18:00 UTC = 21:00 МСК
    $moment = new DateTimeImmutable('2025-01-15 18:00:00 UTC');

    expect($learn->isScheduledToday($moment, domainZone()))->toBeTrue()
        ->and($learn->isScheduledFuture($moment, domainZone()))->toBeFalse()
        ->and($learn->isOverdue($moment, domainZone()))->toBeFalse()
        ->and($learn->category($moment, domainZone()))->toBe(LearnCategory::ScheduledToday);
});

it('categorises scheduled-for-future as ScheduledFuture', function () {
    $learn = scheduledAt('2025-01-20 09:00:00 UTC');
    $moment = new DateTimeImmutable('2025-01-15 12:00:00 UTC');

    expect($learn->isScheduledFuture($moment, domainZone()))->toBeTrue()
        ->and($learn->isScheduledToday($moment, domainZone()))->toBeFalse()
        ->and($learn->isOverdue($moment, domainZone()))->toBeFalse()
        ->and($learn->category($moment, domainZone()))->toBe(LearnCategory::ScheduledFuture);
});

it('categorises scheduled-for-yesterday as Overdue', function () {
    $learn = scheduledAt('2025-01-14 09:00:00 UTC');
    $moment = new DateTimeImmutable('2025-01-15 12:00:00 UTC');

    expect($learn->isOverdue($moment, domainZone()))->toBeTrue()
        ->and($learn->isScheduledToday($moment, domainZone()))->toBeFalse()
        ->and($learn->isScheduledFuture($moment, domainZone()))->toBeFalse()
        ->and($learn->category($moment, domainZone()))->toBe(LearnCategory::Overdue);
});

// ─── граничные случаи таймзон ───────────────────────────────────

it('uses local day boundary, not UTC', function () {
    // 22:30 UTC 15 января = 01:30 МСК 16 января
    $learn = scheduledAt('2025-01-15 22:30:00 UTC');
    // 12:00 UTC 15 января = 15:00 МСК 15 января
    $moment = new DateTimeImmutable('2025-01-15 12:00:00 UTC');

    // В МСК: date = 16 января, moment = 15 января → date в будущем
    // В UTC: date = 15 января, moment = 15 января → сегодня
    expect($learn->category($moment, domainZone('Europe/Moscow')))->toBe(LearnCategory::ScheduledFuture)
        ->and($learn->category($moment, domainZone('UTC')))->toBe(LearnCategory::ScheduledToday);
});

it('does not leak "today" across timezone boundary', function () {
    // 22:30 UTC 14 января = 01:30 МСК 15 января (сегодня в МСК)
    $learn = scheduledAt('2025-01-14 22:30:00 UTC');
    // 12:00 UTC 15 января = 15:00 МСК 15 января
    $moment = new DateTimeImmutable('2025-01-15 12:00:00 UTC');

    // В МСК: сегодня. В UTC: вчера (overdue).
    expect($learn->category($moment, domainZone('Europe/Moscow')))->toBe(LearnCategory::ScheduledToday)
        ->and($learn->category($moment, domainZone('UTC')))->toBe(LearnCategory::Overdue);
});
