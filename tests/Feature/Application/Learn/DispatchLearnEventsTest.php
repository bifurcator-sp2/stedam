<?php

declare(strict_types=1);

use App\Application\Learn\Actions\CancelLearnAction;
use App\Application\Learn\Actions\GradeLearnAction;
use App\Application\Learn\Actions\MarkDoneAction;
use App\Application\Learn\Actions\ScheduleLearnAction;
use App\Application\Learn\Actions\StartLearnAction;
use App\Application\Learn\Context\ViewerContext;
use App\Application\Learn\Context\ViewerRole;
use App\Application\Learn\DTOs\GradeLearnData;
use App\Application\Learn\DTOs\MarkDoneData;
use App\Application\Learn\DTOs\ScheduleLearnData;
use App\Application\Learn\DTOs\StartLearnData;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\Events\LearnStatusChanged;
use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LearnAnswers;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Domain\Learn\ValueObjects\UserId;
use Illuminate\Support\Facades\Event;
use Tests\Support\Fakes\FakeAnswerStorage;
use Tests\Support\Fakes\FakeLearnQuestionsBuilder;
use Tests\Support\Fakes\FakeLessonRepository;
use Tests\Support\Fakes\FakeSnapshotResolver;
use Tests\Support\Fakes\FakeStatusLogger;
use Tests\Support\Fakes\InMemoryLearnRepository;
use Tests\Support\Fakes\StubContextResolver;
use Tests\Support\FrozenClock;

/**
 * §17.1: каждое изменение статуса уходит в шину Laravel как
 * LearnStatusChanged — на нём висят подписчики смежных контекстов (Course).
 *
 * Проверяем именно dispatch, а не запись в аудит: аудит уже покрыт другими
 * тестами через FakeStatusLogger.
 */
function learnEventsClock(): FrozenClock
{
    return new FrozenClock(new DateTimeImmutable('2025-01-05 09:00:00 UTC'));
}

function learnEventsStudent(): StubContextResolver
{
    return new StubContextResolver([1 => new ViewerContext(
        viewerId: 1,
        roles: [ViewerRole::Student],
        visibleUserIds: [1],
        canGrade: false, canAssign: false, canSchedule: false,
        canCancel: false, canStart: true, canSave: true,
    )]);
}

function learnEventsTeacher(): StubContextResolver
{
    return new StubContextResolver([2 => new ViewerContext(
        viewerId: 2,
        roles: [ViewerRole::Teacher],
        visibleUserIds: [1, 2],
        canGrade: true, canAssign: true, canSchedule: true,
        canCancel: true, canStart: false, canSave: false,
    )]);
}

/** Прохождение у ученика 1 в статусе done, готовое к оценке учителем. */
function seedLearnReadyToGrade(InMemoryLearnRepository $repo): Learn
{
    $learn = Learn::startNow(
        userId: new UserId(1),
        lessonId: new LessonId(10),
        snapshot: SnapshotHash::fromContent('lesson-10'),
        questions: domainQuestions(1),
        now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    );
    $learn = $repo->save($learn);
    $learn->markDone(LearnAnswers::empty(), new DateTimeImmutable('2025-01-02 12:00:00 UTC'));
    $learn = $repo->save($learn);

    // Гасим события старта и завершения: тест интересует только оценка.
    $learn->pullEvents();

    return $learn;
}

it('start публикует LearnStatusChanged со статусом in_progress', function () {
    Event::fake();

    $lessons = new FakeLessonRepository();
    $lessons->add(10);
    $action = new StartLearnAction(
        new InMemoryLearnRepository(),
        $lessons,
        new FakeSnapshotResolver(),
        new FakeLearnQuestionsBuilder(),
        new FakeStatusLogger(),
        learnEventsStudent(),
        learnEventsClock(),
    );

    $action(new StartLearnData(new UserId(1), new LessonId(10)));

    Event::assertDispatched(
        LearnStatusChanged::class,
        fn (LearnStatusChanged $e) => $e->status === LearnStatus::InProgress
            && $e->lessonId->value === 10,
    );
});

it('start нового прохождения НЕ дублирует событие из ручного log()', function () {
    Event::fake();

    $lessons = new FakeLessonRepository();
    $lessons->add(10);
    $logger = new FakeStatusLogger();
    $action = new StartLearnAction(
        new InMemoryLearnRepository(),
        $lessons,
        new FakeSnapshotResolver(),
        new FakeLearnQuestionsBuilder(),
        $logger,
        learnEventsStudent(),
        learnEventsClock(),
    );

    $action(new StartLearnData(new UserId(1), new LessonId(10)));

    // Одно событие в шину (буфер агрегата у нового прохождения пуст)…
    Event::assertDispatchedTimes(LearnStatusChanged::class, 1);
    // …и ровно одна запись аудита из ручного logInitial-эквивалента.
    expect($logger->events)->toHaveCount(1);
});

it('schedule публикует LearnStatusChanged со статусом scheduled', function () {
    Event::fake();

    $lessons = new FakeLessonRepository();
    $lessons->add(10);
    $action = new ScheduleLearnAction(
        new InMemoryLearnRepository(),
        $lessons,
        learnEventsTeacher(),
        new FakeStatusLogger(),
        learnEventsClock(),
    );

    $action(new ScheduleLearnData(
        viewerId: new UserId(2),
        targetUserId: new UserId(1),
        lessonId: new LessonId(10),
        scheduleDate: new ScheduleDate(new DateTimeImmutable('2025-02-01 10:00:00 UTC')),
    ));

    Event::assertDispatched(
        LearnStatusChanged::class,
        fn (LearnStatusChanged $e) => $e->status === LearnStatus::Scheduled
            && $e->scheduleDate !== null,
    );
});

it('schedule (assign без даты) публикует статус created', function () {
    Event::fake();

    $lessons = new FakeLessonRepository();
    $lessons->add(10);
    $action = new ScheduleLearnAction(
        new InMemoryLearnRepository(),
        $lessons,
        learnEventsTeacher(),
        new FakeStatusLogger(),
        learnEventsClock(),
    );

    $action(new ScheduleLearnData(
        viewerId: new UserId(2),
        targetUserId: new UserId(1),
        lessonId: new LessonId(10),
        scheduleDate: null,
    ));

    Event::assertDispatched(
        LearnStatusChanged::class,
        fn (LearnStatusChanged $e) => $e->status === LearnStatus::Created,
    );
});

it('cancel публикует LearnStatusChanged со статусом created', function () {
    Event::fake();

    $repo = new InMemoryLearnRepository();
    $learn = $repo->save(Learn::schedule(
        new UserId(1),
        new LessonId(10),
        new ScheduleDate(new DateTimeImmutable('2025-02-01 10:00:00 UTC')),
        new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    ));

    // Начальное событие уже «погашено» — иначе поймаем его, а не cancel.
    $learn->pullEvents();

    $action = new CancelLearnAction(
        $repo,
        learnEventsTeacher(),
        new FakeStatusLogger(),
        learnEventsClock(),
    );

    $action($learn->id(), new UserId(2));

    Event::assertDispatched(
        LearnStatusChanged::class,
        fn (LearnStatusChanged $e) => $e->status === LearnStatus::Created
            && $e->scheduleDate === null,
    );
});

it('markDone публикует LearnStatusChanged со статусом done', function () {
    Event::fake();

    $repo = new InMemoryLearnRepository();
    $learn = $repo->save(Learn::startNow(
        userId: new UserId(1),
        lessonId: new LessonId(10),
        snapshot: SnapshotHash::fromContent('lesson-10'),
        questions: domainQuestions(1),
        now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    ));

    $action = new MarkDoneAction(
        $repo,
        learnEventsStudent(),
        new FakeStatusLogger(),
        new FakeAnswerStorage(),
        learnEventsClock(),
    );

    $action(new MarkDoneData($learn->id(), new UserId(1)));

    Event::assertDispatched(
        LearnStatusChanged::class,
        fn (LearnStatusChanged $e) => $e->status === LearnStatus::Done,
    );
});

it('grade публикует LearnStatusChanged со статусом graded', function () {
    Event::fake();

    $repo = new InMemoryLearnRepository();
    $learn = seedLearnReadyToGrade($repo);

    $action = new GradeLearnAction(
        $repo,
        learnEventsTeacher(),
        new FakeStatusLogger(),
        learnEventsClock(),
    );

    $action(new GradeLearnData($learn->id(), new UserId(2), new Grade(90.0)));

    // Именно этот статус нужен курсам: по нему пересчитывается прогресс.
    Event::assertDispatched(
        LearnStatusChanged::class,
        fn (LearnStatusChanged $e) => $e->status === LearnStatus::Graded
            && $e->learnId->value === $learn->id()->value,
    );
});

it('аудит пишется раньше шины: падение подписчика не теряет запись', function () {
    $repo = new InMemoryLearnRepository();
    $learn = seedLearnReadyToGrade($repo);

    // Подписчик падает — но аудит уже записан, потому что log() идёт первым.
    Event::listen(LearnStatusChanged::class, static function (): void {
        throw new RuntimeException('подписчик упал');
    });

    $logger = new FakeStatusLogger();
    $action = new GradeLearnAction(
        $repo,
        learnEventsTeacher(),
        $logger,
        learnEventsClock(),
    );

    try {
        $action(new GradeLearnData($learn->id(), new UserId(2), new Grade(80.0)));
    } catch (RuntimeException) {
        // ожидаемо — падение подписчика пробрасывается
    }

    expect($logger->events)->toHaveCount(1)
        ->and($logger->events[0]['status'])->toBe(LearnStatus::Graded);
});
