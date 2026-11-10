<?php

declare(strict_types=1);

use App\Application\Learn\Actions\CancelLearnAction;
use App\Application\Learn\Actions\GradeLearnAction;
use App\Application\Learn\Actions\MarkDoneAction;
use App\Application\Learn\Actions\SaveAnswersAction;
use App\Application\Learn\Actions\ScheduleLearnAction;
use App\Application\Learn\Context\ViewerContext;
use App\Application\Learn\Context\ViewerRole;
use App\Application\Learn\DTOs\GradeLearnData;
use App\Application\Learn\DTOs\MarkDoneData;
use App\Application\Learn\DTOs\SaveAnswersData;
use App\Application\Learn\DTOs\ScheduleLearnData;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\Exceptions\InvalidStatusTransition;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Domain\Learn\ValueObjects\UserId;
use Tests\Support\Fakes\FakeLessonRepository;
use Tests\Support\Fakes\FakeStatusLogger;
use Tests\Support\Fakes\InMemoryLearnRepository;
use Tests\Support\Fakes\StubContextResolver;
use Tests\Support\FrozenClock;

/**
 * Готовит окружение: репозиторий с learn in_progress для пользователя $ownerUserId.
 *
 * @return array{0: InMemoryLearnRepository, 1: Learn}
 */
function seedInProgress(int $ownerUserId, int $lessonId = 10): array
{
    $repo = new InMemoryLearnRepository();
    $learn = Learn::startNow(
        userId: new UserId($ownerUserId),
        lessonId: new LessonId($lessonId),
        snapshot: SnapshotHash::fromContent("lesson-{$lessonId}"),
        now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    );

    return [$repo, $repo->save($learn)];
}

function studentContext(int $viewerId): ViewerContext
{
    return new ViewerContext(
        viewerId: $viewerId,
        roles: [ViewerRole::Student],
        visibleUserIds: [$viewerId],
        canGrade: false, canAssign: false, canSchedule: false,
        canCancel: false, canStart: true, canSave: true,
    );
}

function teacherContext(int $viewerId, array $studentIds): ViewerContext
{
    return new ViewerContext(
        viewerId: $viewerId,
        roles: [ViewerRole::Teacher],
        visibleUserIds: array_merge([$viewerId], $studentIds),
        canGrade: true, canAssign: true, canSchedule: true,
        canCancel: true, canStart: false, canSave: false,
    );
}

$clock = fn () => new FrozenClock(new DateTimeImmutable('2025-01-05 09:00:00 UTC'));

// ==================== saveAnswers ====================

it('student saves answers on own in_progress learn', function () use ($clock) {
    [$repo, $learn] = seedInProgress(5);
    $resolver = new StubContextResolver([5 => studentContext(5)]);
    $action = new SaveAnswersAction($repo, $resolver, new \Tests\Support\Fakes\FakeAnswerStorage());

    $result = $action(new SaveAnswersData($learn->id(), new UserId(5), [
        rawAnswer(1),
        rawAnswer(2),
    ]));

    expect($result->answers()->toArray())->toBe([
        ['index' => 1, 'file' => "answers/{$learn->id()->value}/1.webp"],
        ['index' => 2, 'file' => "answers/{$learn->id()->value}/2.webp"],
    ]);
});

it('student cannot save answers on someone else learn', function () use ($clock) {
    [$repo, $learn] = seedInProgress(5);
    // viewer 6 видит только себя (student), но learn принадлежит 5
    $resolver = new StubContextResolver([6 => studentContext(6)]);
    $action = new SaveAnswersAction($repo, $resolver, new \Tests\Support\Fakes\FakeAnswerStorage());

    expect(fn () => $action(new SaveAnswersData($learn->id(), new UserId(6), [rawAnswer(1)])))
        ->toThrow(LearnNotFound::class); // вне scope → not found
});

// ==================== markDone ====================

it('student completes own learn, carries autosaved answers', function () use ($clock) {
    [$repo, $learn] = seedInProgress(5);
    $learn->updateAnswers(answerImages(1));
    $repo->save($learn);

    $resolver = new StubContextResolver([5 => studentContext(5)]);
    $action = new MarkDoneAction($repo, $resolver, new FakeStatusLogger(), new \Tests\Support\Fakes\FakeAnswerStorage(), $clock());

    $result = $action(new MarkDoneData($learn->id(), new UserId(5)));

    expect($result->status())->toBe(LearnStatus::Done)
        ->and($result->answers()->toArray())->toBe([['index' => 1, 'file' => 'answers/1.webp']])
        ->and($result->completedAt())->not->toBeNull();
});

it('student completes with overriding final answers', function () use ($clock) {
    [$repo, $learn] = seedInProgress(5);
    $resolver = new StubContextResolver([5 => studentContext(5)]);
    $action = new MarkDoneAction($repo, $resolver, new FakeStatusLogger(), new \Tests\Support\Fakes\FakeAnswerStorage(), $clock());

    $result = $action(new MarkDoneData($learn->id(), new UserId(5), [rawAnswer(3)]));

    expect($result->answers()->toArray())->toBe([['index' => 3, 'file' => "answers/{$learn->id()->value}/3.webp"]]);
});

// ==================== grade ====================

it('teacher grades done learn of own student', function () use ($clock) {
    [$repo, $learn] = seedInProgress(5);
    $learn->markDone(\App\Domain\Learn\ValueObjects\LearnAnswers::empty(), new DateTimeImmutable());
    $repo->save($learn);

    $resolver = new StubContextResolver([1 => teacherContext(1, [5])]);
    $logger = new FakeStatusLogger();
    $action = new GradeLearnAction($repo, $resolver, $logger, $clock());

    $result = $action(new GradeLearnData($learn->id(), new UserId(1), new Grade(90.0)));

    expect($result->status())->toBe(LearnStatus::Graded)
        ->and($result->grade()->value)->toBe(90.0)
        ->and($result->gradedBy()->value)->toBe(1)
        ->and($logger->events)->not->toBeEmpty();
});

it('teacher cannot grade learn outside scope', function () use ($clock) {
    [$repo, $learn] = seedInProgress(5);
    $learn->markDone(\App\Domain\Learn\ValueObjects\LearnAnswers::empty(), new DateTimeImmutable());
    $repo->save($learn);

    // учитель 1 не видит ученика 5 (scope только [1, 7])
    $resolver = new StubContextResolver([1 => teacherContext(1, [7])]);
    $action = new GradeLearnAction($repo, $resolver, new FakeStatusLogger(), $clock());

    expect(fn () => $action(new GradeLearnData($learn->id(), new UserId(1), new Grade(50.0))))
        ->toThrow(LearnNotFound::class);
});

// ==================== schedule / assign ====================

it('teacher assigns (no date) creates created learn', function () use ($clock) {
    $repo = new InMemoryLearnRepository();
    $lessons = new FakeLessonRepository();
    $lessons->add(10);

    $resolver = new StubContextResolver([1 => teacherContext(1, [5])]);
    $action = new ScheduleLearnAction($repo, $lessons, $resolver, new FakeStatusLogger(), $clock());

    $result = $action(new ScheduleLearnData(
        viewerId: new UserId(1),
        targetUserId: new UserId(5),
        lessonId: new LessonId(10),
        scheduleDate: null,
    ));

    expect($result->status())->toBe(LearnStatus::Created)
        ->and($result->userId()->value)->toBe(5);
});

it('teacher schedules (with date) creates scheduled learn', function () use ($clock) {
    $repo = new InMemoryLearnRepository();
    $lessons = new FakeLessonRepository();
    $lessons->add(10);

    $resolver = new StubContextResolver([1 => teacherContext(1, [5])]);
    $action = new ScheduleLearnAction($repo, $lessons, $resolver, new FakeStatusLogger(), $clock());

    $result = $action(new ScheduleLearnData(
        viewerId: new UserId(1),
        targetUserId: new UserId(5),
        lessonId: new LessonId(10),
        scheduleDate: new ScheduleDate(new DateTimeImmutable('2025-02-01 10:00:00 UTC')),
    ));

    expect($result->status())->toBe(LearnStatus::Scheduled)
        ->and($result->scheduleDate())->not->toBeNull();
});

it('teacher cannot schedule for user outside scope', function () use ($clock) {
    $repo = new InMemoryLearnRepository();
    $lessons = new FakeLessonRepository();
    $lessons->add(10);

    $resolver = new StubContextResolver([1 => teacherContext(1, [7])]);
    $action = new ScheduleLearnAction($repo, $lessons, $resolver, new FakeStatusLogger(), $clock());

    expect(fn () => $action(new ScheduleLearnData(
        viewerId: new UserId(1),
        targetUserId: new UserId(5), // 5 не в scope
        lessonId: new LessonId(10),
    )))->toThrow(LearnAccessDenied::class);
});

// ==================== cancel ====================

it('teacher cancels scheduled learn of own student', function () use ($clock) {
    $repo = new InMemoryLearnRepository();
    $now = new DateTimeImmutable('2025-01-01 12:00:00 UTC');
    $learn = Learn::schedule(
        new UserId(5),
        new LessonId(10),
        new ScheduleDate(new DateTimeImmutable('2025-02-01 10:00:00 UTC')),
        $now,
    );
    $learn = $repo->save($learn);

    $resolver = new StubContextResolver([1 => teacherContext(1, [5])]);
    $action = new CancelLearnAction($repo, $resolver, new FakeStatusLogger(), $clock());

    $result = $action($learn->id(), new UserId(1));

    expect($result->status())->toBe(LearnStatus::Created)
        ->and($result->scheduleDate())->toBeNull();
});

it('teacher cannot cancel already in_progress learn (invalid transition)', function () use ($clock) {
    [$repo, $learn] = seedInProgress(5);
    $resolver = new StubContextResolver([1 => teacherContext(1, [5])]);
    $action = new CancelLearnAction($repo, $resolver, new FakeStatusLogger(), $clock());

    expect(fn () => $action($learn->id(), new UserId(1)))
        ->toThrow(InvalidStatusTransition::class);
});
