<?php

declare(strict_types=1);

use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\Events\LearnStatusChanged;
use App\Domain\Learn\Exceptions\InvalidStatusTransition;
use App\Domain\Learn\Exceptions\LearnAlreadyGraded;
use App\Domain\Learn\Exceptions\SnapshotMissing;
use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LearnAnswers;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\UserId;

// ─────────────────────────────────────────────────────────────
// Фабрики
// ─────────────────────────────────────────────────────────────

describe('startNow', function () {
    it('creates in_progress with snapshot', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());

        expect($learn->id())->toBeNull()
            ->and($learn->status())->toBe(LearnStatus::InProgress)
            ->and($learn->snapshot())->not->toBeNull()
            ->and($learn->grade())->toBeNull()
            ->and($learn->gradedBy())->toBeNull()
            ->and($learn->scheduleDate())->toBeNull()
            ->and($learn->answers()->isEmpty())->toBeTrue()
            ->and($learn->completedAt())->toBeNull()
            ->and($learn->createdAt())->toEqual(domainNow())
            ->and($learn->statusDate())->toEqual(domainNow());
    });
});

describe('schedule', function () {
    it('creates scheduled with date, no snapshot', function () {
        $date = new ScheduleDate(new DateTimeImmutable('2025-06-01 09:00:00 UTC'));
        $learn = Learn::schedule(new UserId(1), new LessonId(10), $date, domainNow());

        expect($learn->status())->toBe(LearnStatus::Scheduled)
            ->and($learn->scheduleDate())->toBe($date)
            ->and($learn->snapshot())->toBeNull();
    });
});

describe('assign', function () {
    it('creates in created without date or snapshot', function () {
        $learn = Learn::assign(new UserId(1), new LessonId(10), domainNow());

        expect($learn->status())->toBe(LearnStatus::Created)
            ->and($learn->scheduleDate())->toBeNull()
            ->and($learn->snapshot())->toBeNull();
    });
});

// ─────────────────────────────────────────────────────────────
// Reconstitute
// ─────────────────────────────────────────────────────────────

describe('reconstitute', function () {
    it('hydrates a valid graded learn', function () {
        $learn = Learn::reconstitute(
            new LearnId(1),
            new UserId(10),
            new LessonId(20),
            LearnStatus::Graded,
            domainSnapshot(),
            new Grade(90),
            new UserId(5),
            null,
            answerImages(1),
            domainNow(),
            null,
            domainLater(),
            domainLater(),
        );

        expect($learn->id()?->value)->toBe(1)
            ->and($learn->status())->toBe(LearnStatus::Graded)
            ->and($learn->grade()?->value)->toBe(90.0)
            ->and($learn->gradedBy()?->value)->toBe(5)
            ->and($learn->completedAt())->toEqual(domainLater());
    });

    it('rejects in_progress without snapshot', function () {
        expect(fn () => Learn::reconstitute(
            new LearnId(1), new UserId(10), new LessonId(20),
            LearnStatus::InProgress, null, null, null, null,
            LearnAnswers::empty(),
            domainNow(), null, domainNow(), null,
        ))->toThrow(SnapshotMissing::class);
    });

    it('rejects scheduled without schedule_date', function () {
        expect(fn () => Learn::reconstitute(
            new LearnId(1), new UserId(10), new LessonId(20),
            LearnStatus::Scheduled, null, null, null, null,
            LearnAnswers::empty(),
            domainNow(), null, domainNow(), null,
        ))->toThrow(InvalidStatusTransition::class);
    });

    it('rejects created with schedule_date', function () {
        expect(fn () => Learn::reconstitute(
            new LearnId(1), new UserId(10), new LessonId(20),
            LearnStatus::Created, null, null, null,
            new ScheduleDate(domainNow()), // §14.2: created не имеет scheduleDate
            LearnAnswers::empty(),
            domainNow(), null, domainNow(), null,
        ))->toThrow(InvalidStatusTransition::class);
    });

    it('rejects graded without grade', function () {
        expect(fn () => Learn::reconstitute(
            new LearnId(1), new UserId(10), new LessonId(20),
            LearnStatus::Graded, domainSnapshot(), null, new UserId(5), null,
            LearnAnswers::empty(),
            domainNow(), null, domainNow(), null,
        ))->toThrow(InvalidStatusTransition::class);
    });

    it('allows graded without graded_by (auto-graded by system)', function () {
        $learn = Learn::reconstitute(
            new LearnId(1), new UserId(10), new LessonId(20),
            LearnStatus::Graded, domainSnapshot(), new Grade(90), null, null,
            LearnAnswers::empty(),
            domainNow(), null, domainNow(), domainNow(),
        );

        expect($learn->status())->toBe(LearnStatus::Graded)
            ->and($learn->grade()?->value)->toBe(90.0)
            ->and($learn->gradedBy())->toBeNull();
    });

    it('hydrates an archived learn', function () {
        $learn = Learn::reconstitute(
            new LearnId(1), new UserId(10), new LessonId(20),
            LearnStatus::InProgress, domainSnapshot(),
            null, null, null,
            LearnAnswers::empty(),
            domainNow(),
            domainLater(),    // archivedAt
            domainNow(),
            null,
        );

        expect($learn->isArchived())->toBeTrue()
            ->and($learn->archivedAt())->toEqual(domainLater());
    });

    it('hydrates a non-archived learn', function () {
        $learn = Learn::reconstitute(
            new LearnId(1), new UserId(10), new LessonId(20),
            LearnStatus::InProgress, domainSnapshot(),
            null, null, null,
            LearnAnswers::empty(),
            domainNow(),
            null,             // archivedAt = null
            domainNow(),
            null,
        );

        expect($learn->isArchived())->toBeFalse();
    });
});

// ─────────────────────────────────────────────────────────────
// Переходы
// ─────────────────────────────────────────────────────────────

describe('start', function () {
    it('transitions created → in_progress, attaching snapshot', function () {
        $learn = Learn::assign(new UserId(1), new LessonId(10), domainNow());
        $learn->start(domainSnapshot(), domainQuestions(), domainLater());

        expect($learn->status())->toBe(LearnStatus::InProgress)
            ->and($learn->snapshot())->not->toBeNull()
            ->and($learn->statusDate())->toEqual(domainLater());
    });

    it('transitions scheduled → in_progress', function () {
        $date = new ScheduleDate(new DateTimeImmutable('2025-06-01'));
        $learn = Learn::schedule(new UserId(1), new LessonId(10), $date, domainNow());
        $learn->start(domainSnapshot(), domainQuestions(), domainLater());

        expect($learn->status())->toBe(LearnStatus::InProgress)
            ->and($learn->scheduleDate())->toBe($date); // история даты сохраняется
    });

    it('forbids starting from in_progress', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());

        expect(fn () => $learn->start(domainSnapshot(), domainQuestions(), domainLater()))
            ->toThrow(InvalidStatusTransition::class);
    });
});

describe('reschedule', function () {
    it('updates date on a scheduled learn', function () {
        $first = new ScheduleDate(new DateTimeImmutable('2025-06-01'));
        $second = new ScheduleDate(new DateTimeImmutable('2025-07-01'));

        $learn = Learn::schedule(new UserId(1), new LessonId(10), $first, domainNow());
        $learn->reschedule($second, domainLater());

        expect($learn->scheduleDate())->toBe($second)
            ->and($learn->status())->toBe(LearnStatus::Scheduled)
            ->and($learn->statusDate())->toEqual(domainLater());
    });

    it('forbids rescheduling a non-scheduled learn', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());

        expect(fn () => $learn->reschedule(
            new ScheduleDate(new DateTimeImmutable('2025-07-01')),
            domainLater(),
        ))->toThrow(InvalidStatusTransition::class);
    });
});

describe('cancel', function () {
    it('returns scheduled back to created', function () {
        $learn = Learn::schedule(
            new UserId(1),
            new LessonId(10),
            new ScheduleDate(new DateTimeImmutable('2025-06-01')),
            domainNow(),
        );
        $learn->cancel(domainLater());

        expect($learn->status())->toBe(LearnStatus::Created)
            ->and($learn->scheduleDate())->toBeNull();
    });

    it('forbids cancelling in_progress', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());

        expect(fn () => $learn->cancel(domainLater()))
            ->toThrow(InvalidStatusTransition::class);
    });

    it('does not touch state if cancel is invalid', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $before = $learn->status();

        try {
            $learn->cancel(domainLater());
        } catch (InvalidStatusTransition) {
            // expected
        }

        expect($learn->status())->toBe($before);
    });
});

describe('markDone', function () {
    it('transitions in_progress → done, storing answers and completedAt', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $learn->markDone(answerImages(1), domainLater());

        expect($learn->status())->toBe(LearnStatus::Done)
            ->and($learn->answers()->toArray())->toBe([['index' => 1, 'file' => 'answers/1.webp']])
            ->and($learn->statusDate())->toEqual(domainLater())
            ->and($learn->completedAt())->toEqual(domainLater());
    });

    it('forbids marking done twice', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $learn->markDone(LearnAnswers::empty(), domainLater());

        expect(fn () => $learn->markDone(LearnAnswers::empty(), domainLater()))
            ->toThrow(InvalidStatusTransition::class);
    });

    it('forbids marking scheduled as done directly', function () {
        $learn = Learn::schedule(
            new UserId(1),
            new LessonId(10),
            new ScheduleDate(new DateTimeImmutable('2025-06-01')),
            domainNow(),
        );

        expect(fn () => $learn->markDone(LearnAnswers::empty(), domainLater()))
            ->toThrow(InvalidStatusTransition::class);
    });
});

describe('markGraded', function () {
    it('transitions done → graded', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $learn->markDone(LearnAnswers::empty(), domainLater());
        $learn->markGraded(new Grade(85.5), new UserId(2), domainLater());

        expect($learn->status())->toBe(LearnStatus::Graded)
            ->and($learn->grade()?->value)->toBe(85.5)
            ->and($learn->gradedBy()?->value)->toBe(2);
    });

    it('allows auto-grading without user (gradedBy = null)', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $learn->markDone(LearnAnswers::empty(), domainLater());
        $learn->markGraded(new Grade(100), null, domainLater());

        expect($learn->status())->toBe(LearnStatus::Graded)
            ->and($learn->grade()?->value)->toBe(100.0)
            ->and($learn->gradedBy())->toBeNull();
    });

    it('keeps completedAt after grading', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $learn->markDone(LearnAnswers::empty(), domainLater());

        $gradedAt = new DateTimeImmutable('2025-01-01 15:00:00 UTC');
        $learn->markGraded(new Grade(70), new UserId(2), $gradedAt);

        // completedAt — момент done, не перетирается при graded
        expect($learn->completedAt())->toEqual(domainLater())
            ->and($learn->statusDate())->toEqual($gradedAt);
    });

    it('throws when already graded', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $learn->markDone(LearnAnswers::empty(), domainLater());
        $learn->markGraded(new Grade(85.5), new UserId(2), domainLater());

        expect(fn () => $learn->markGraded(new Grade(90), new UserId(2), domainLater()))
            ->toThrow(LearnAlreadyGraded::class);
    });

    it('forbids grading from in_progress', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());

        expect(fn () => $learn->markGraded(new Grade(85), new UserId(2), domainLater()))
            ->toThrow(InvalidStatusTransition::class);
    });

    it('forbids grading from scheduled', function () {
        $learn = Learn::schedule(
            new UserId(1),
            new LessonId(10),
            new ScheduleDate(new DateTimeImmutable('2025-06-01')),
            domainNow(),
        );

        expect(fn () => $learn->markGraded(new Grade(85), new UserId(2), domainLater()))
            ->toThrow(InvalidStatusTransition::class);
    });
});

// ─────────────────────────────────────────────────────────────
// Доменные события
// ─────────────────────────────────────────────────────────────

describe('domain events', function () {
    it('does not emit status events before the aggregate has an id', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());

        expect($learn->pullEvents())->toBe([]);
    });

    it('emits a status event on transition (after id is assigned)', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow())
            ->withId(new LearnId(7));

        $learn->markDone(LearnAnswers::empty(), domainLater());

        $events = $learn->pullEvents();

        expect($events)->toHaveCount(1)
            ->and($events[0])->toBeInstanceOf(LearnStatusChanged::class)
            ->and($events[0]->learnId->value)->toBe(7)
            ->and($events[0]->lessonId->value)->toBe(10)
            ->and($events[0]->status)->toBe(LearnStatus::Done)
            ->and($events[0]->statusDate)->toEqual(domainLater());
    });

    it('pullEvents drains accumulated events', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow())
            ->withId(new LearnId(7));

        $learn->markDone(LearnAnswers::empty(), domainLater());

        expect($learn->pullEvents())->toHaveCount(1)
            ->and($learn->pullEvents())->toBe([]);
    });

    it('emits reschedule event with schedule_date', function () {
        $learn = Learn::schedule(
            new UserId(1),
            new LessonId(10),
            new ScheduleDate(new DateTimeImmutable('2025-06-01')),
            domainNow(),
        )->withId(new LearnId(7));

        $second = new ScheduleDate(new DateTimeImmutable('2025-07-01'));
        $learn->reschedule($second, domainLater());

        $events = $learn->pullEvents();

        expect($events)->toHaveCount(1)
            ->and($events[0]->status)->toBe(LearnStatus::Scheduled)
            ->and($events[0]->scheduleDate)->toBe($second);
    });
});

// ─────────────────────────────────────────────────────────────
// Идентичность
// ─────────────────────────────────────────────────────────────

describe('withId', function () {
    it('returns a clone with id, leaving original untouched', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $saved = $learn->withId(new LearnId(42));

        expect($saved)->not->toBe($learn)
            ->and($saved->id()?->value)->toBe(42)
            ->and($learn->id())->toBeNull();
    });

    it('refuses to set id twice', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow())
            ->withId(new LearnId(42));

        expect(fn () => $learn->withId(new LearnId(43)))
            ->toThrow(LogicException::class);
    });
});
