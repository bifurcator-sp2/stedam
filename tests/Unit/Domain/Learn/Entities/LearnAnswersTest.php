<?php

declare(strict_types=1);

use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Exceptions\InvalidStatusTransition;
use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LearnAnswers;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\UserId;

describe('updateAnswers', function () {
    it('replaces answers in in_progress', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(1, 2), domainNow());
        $learn->updateAnswers(answerImages(1));

        expect($learn->answers()->toArray())->toBe([['index' => 1, 'file' => 'answers/1.webp']]);

        // полная замена, не мерж
        $learn->updateAnswers(answerImages(2));
        expect($learn->answers()->toArray())->toBe([['index' => 2, 'file' => 'answers/2.webp']]);
    });

    it('can clear answers', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $learn->updateAnswers(answerImages(1));
        $learn->updateAnswers(LearnAnswers::empty());

        expect($learn->answers()->isEmpty())->toBeTrue();
    });

    it('does not change status or statusDate', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $before = $learn->statusDate();

        $learn->updateAnswers(answerImages(1));

        expect($learn->statusDate())->toEqual($before);
    });

    it('forbids updating answers outside in_progress', function (Learn $learn) {
        expect(fn () => $learn->updateAnswers(answerImages(1)))
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
            $l = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
            $l->markDone(LearnAnswers::empty(), domainLater());
            return $l;
        }],
        'graded'    => [function () {
            $l = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
            $l->markDone(LearnAnswers::empty(), domainLater());
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
        'in_progress' => [fn () => Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow())],
        'done'        => [function () {
            $l = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
            $l->markDone(LearnAnswers::empty(), domainLater());
            return $l;
        }],
        'graded'      => [function () {
            $l = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
            $l->markDone(LearnAnswers::empty(), domainLater());
            $l->markGraded(new Grade(90), new UserId(2), domainLater());
            return $l;
        }],
    ]);

    it('does not change status', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $before = $learn->status();

        $learn->archive(domainLater());

        expect($learn->status())->toBe($before);
    });

    it('is idempotent', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $learn->archive(domainLater());

        $second = new DateTimeImmutable('2025-06-01');
        $learn->archive($second);

        // вторая дата не перезаписывает первую
        expect($learn->archivedAt())->toEqual(domainLater());
    });

    it('allows transitions on archived learns', function () {
        // архивация — флаг видимости, не заморозка
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $learn->archive(domainNow());
        $learn->markDone(LearnAnswers::empty(), domainLater());

        expect($learn->status()->value)->toBe('done')
            ->and($learn->isArchived())->toBeTrue();
    });
});

describe('unarchive', function () {
    it('clears archivedAt', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $learn->archive(domainLater());
        $learn->unarchive();

        expect($learn->isArchived())->toBeFalse()
            ->and($learn->archivedAt())->toBeNull();
    });

    it('is idempotent on non-archived', function () {
        $learn = Learn::startNow(new UserId(1), new LessonId(10), domainSnapshot(), domainQuestions(), domainNow());
        $learn->unarchive();    // не должно падать

        expect($learn->isArchived())->toBeFalse();
    });
});
