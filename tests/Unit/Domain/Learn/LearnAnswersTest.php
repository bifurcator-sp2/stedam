<?php

declare(strict_types=1);

use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Exceptions\InvalidStatusTransition;
use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\UserId;

describe('updateAnswers', function () {
    it('replaces answers in in_progress', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
        $learn->updateAnswers(['q1' => 'a1']);

        expect($learn->answers())->toBe(['q1' => 'a1']);

        // полная замена, не мерж
        $learn->updateAnswers(['q2' => 'a2']);
        expect($learn->answers())->toBe(['q2' => 'a2']);
    });

    it('can clear answers', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
        $learn->updateAnswers(['q1' => 'a1']);
        $learn->updateAnswers([]);

        expect($learn->answers())->toBe([]);
    });

    it('does not change status or statusDate', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
        $before = $learn->statusDate();

        $learn->updateAnswers(['q1' => 'a1']);

        expect($learn->statusDate())->toEqual($before);
    });

    it('forbids updating answers outside in_progress', function (Learn $learn) {
        expect(fn () => $learn->updateAnswers(['q1' => 'a1']))
            ->toThrow(InvalidStatusTransition::class);
    })->with([
        'created'   => [fn () => Learn::assign(new UserId(1), new LessonId(10), domainNow())],
        'scheduled' => [fn () => Learn::schedule(
            new UserId(1),
            new LessonId(10),
            new ScheduleDate(new DateTimeImmutable('2025-06-01')),
            domainNow(),
        )],
        'done'      => [function () {
            $l = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
            $l->markDone([], domainLater());
            return $l;
        }],
        'graded'    => [function () {
            $l = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
            $l->markDone([], domainLater());
            $l->markGraded(new Grade(90), new UserId(2), domainLater());
            return $l;
        }],
    ]);
});

describe('archive', function () {
    it('archives from any status', function (Learn $learn) {
        $learn->archive(domainLater());

        expect($learn->isArchived())->toBeTrue()
            ->and($learn->archivedAt())->toEqual(domainLater());
    })->with([
        'created'     => [fn () => Learn::assign(new UserId(1), new LessonId(10), domainNow())],
        'scheduled'   => [fn () => Learn::schedule(
            new UserId(1), new LessonId(10),
            new ScheduleDate(new DateTimeImmutable('2025-06-01')), domainNow(),
        )],
        'in_progress' => [fn () => Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow())],
        'done'        => [function () {
            $l = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
            $l->markDone([], domainLater());
            return $l;
        }],
        'graded'      => [function () {
            $l = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
            $l->markDone([], domainLater());
            $l->markGraded(new Grade(90), new UserId(2), domainLater());
            return $l;
        }],
    ]);

    it('does not change status', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
        $before = $learn->status();

        $learn->archive(domainLater());

        expect($learn->status())->toBe($before);
    });

    it('is idempotent', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
        $learn->archive(domainLater());

        $second = new DateTimeImmutable('2025-06-01');
        $learn->archive($second);

        // вторая дата не перезаписывает первую
        expect($learn->archivedAt())->toEqual(domainLater());
    });

    it('allows transitions on archived learns', function () {
        // архивация — флаг видимости, не заморозка
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
        $learn->archive(domainNow());
        $learn->markDone([], domainLater());

        expect($learn->status()->value)->toBe('done')
            ->and($learn->isArchived())->toBeTrue();
    });
});

describe('unarchive', function () {
    it('clears archivedAt', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
        $learn->archive(domainLater());
        $learn->unarchive();

        expect($learn->isArchived())->toBeFalse()
            ->and($learn->archivedAt())->toBeNull();
    });

    it('is idempotent on non-archived', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainNow());
        $learn->unarchive();    // не должно падать

        expect($learn->isArchived())->toBeFalse();
    });
});
