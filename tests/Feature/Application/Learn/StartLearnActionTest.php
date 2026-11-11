<?php

declare(strict_types=1);

use App\Application\Learn\Actions\StartLearnAction;
use App\Application\Learn\Context\ViewerContext;
use App\Application\Learn\Context\ViewerRole;
use App\Application\Learn\DTOs\StartLearnData;
use App\Application\Learn\Exceptions\LessonNotFound;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\UserId;
use Tests\Support\Fakes\FakeLearnQuestionsBuilder;
use Tests\Support\Fakes\FakeLessonRepository;
use Tests\Support\Fakes\FakeSnapshotResolver;
use Tests\Support\Fakes\FakeStatusLogger;
use Tests\Support\Fakes\InMemoryLearnRepository;
use Tests\Support\Fakes\StubContextResolver;
use Tests\Support\FrozenClock;

/**
 * Хелпер, собирающий Action со всеми фейками.
 * Возвращает массив: [action, learns, lessons, snapshots, logger, questionsBuilder]
 */
function makeStartLearnAction(
    ?FakeLessonRepository $lessons = null,
    ?FakeSnapshotResolver $snapshots = null,
    ?FakeStatusLogger $logger = null,
    ?StubContextResolver $resolver = null,
    ?FakeLearnQuestionsBuilder $questionsBuilder = null,
): array {
    $learns = new InMemoryLearnRepository();
    $lessons ??= new FakeLessonRepository();
    $snapshots ??= new FakeSnapshotResolver();
    $logger ??= new FakeStatusLogger();
    $questionsBuilder ??= new FakeLearnQuestionsBuilder();
    $clock = new FrozenClock(new DateTimeImmutable('2025-01-01 12:00:00 UTC'));

    // Ученик с правом на старт для всех тестовых userId (1 по умолчанию).
    $resolver ??= new StubContextResolver([
        1 => new ViewerContext(
            viewerId: 1,
            roles: [ViewerRole::Student],
            visibleUserIds: [1],
            canGrade: false, canAssign: false, canSchedule: false,
            canCancel: false, canStart: true, canSave: true,
        ),
    ]);

    $action = new StartLearnAction(
        $learns,
        $lessons,
        $snapshots,
        $questionsBuilder,
        $logger,
        $resolver,
        $clock,
    );

    return [$action, $learns, $lessons, $snapshots, $logger, $questionsBuilder];
}

it('starts a learn for a published lesson', function () {
    [$action, $learns, $lessons, , $logger] = makeStartLearnAction();
    $lessons->add(10); // урок ID 10, опубликован

    $learn = $action(new StartLearnData(new UserId(1), new LessonId(10)));

    expect($learn->id())->not->toBeNull()
        ->and($learn->status())->toBe(LearnStatus::InProgress)
        ->and($learn->snapshot())->not->toBeNull()
        ->and($learns->getById($learn->id()))->toBe($learn) // сохранён в репозиторий
        ->and($logger->events)->toHaveCount(1)              // лог статуса вызван
        ->and($logger->events[0]['status'])->toBe(LearnStatus::InProgress)
        ->and($logger->events[0]['learnId'])->toBe($learn->id()->value);
});

it('throws when lesson is not published', function () {
    [$action, , $lessons] = makeStartLearnAction();
    $lessons->add(10, published: false);

    expect(fn () => $action(new StartLearnData(new UserId(1), new LessonId(10))))
        ->toThrow(LessonNotFound::class);
});

it('is idempotent: second start returns existing learn', function () {
    [$action, , $lessons, $snapshots, $logger] = makeStartLearnAction();
    $lessons->add(10);

    $first = $action(new StartLearnData(new UserId(1), new LessonId(10)));
    $second = $action(new StartLearnData(new UserId(1), new LessonId(10)));

    expect($second->id()->value)->toBe($first->id()->value) // тот же learn
    ->and($snapshots->calls)->toBe(1)                   // снапшот не резолвился второй раз
    ->and($logger->events)->toHaveCount(1);             // статус залогирован один раз
});

it('resolves snapshot exactly once per distinct lesson', function () {
    [$action, , $lessons, $snapshots] = makeStartLearnAction();
    $lessons->add(10);
    $lessons->add(20);

    $action(new StartLearnData(new UserId(1), new LessonId(10)));
    $action(new StartLearnData(new UserId(1), new LessonId(20)));

    expect($snapshots->calls)->toBe(2); // для разных уроков — разные снапшоты
});

it('generates unique questions at start', function () {
    [$action, , $lessons, , , $questionsBuilder] = makeStartLearnAction();
    $lessons->add(10);

    $learn = $action(new StartLearnData(new UserId(1), new LessonId(10)));

    expect($questionsBuilder->calls)->toBe(1)
        ->and($learn->questions()->isEmpty())->toBeFalse()
        ->and($learn->questions()->indices())->toBe([1]);
});

it('does not regenerate questions on repeated start (idempotent)', function () {
    [$action, , $lessons, , , $questionsBuilder] = makeStartLearnAction();
    $lessons->add(10);

    $action(new StartLearnData(new UserId(1), new LessonId(10)));
    $action(new StartLearnData(new UserId(1), new LessonId(10)));

    // Задачи не перегенерируются для уже идущего прохождения.
    expect($questionsBuilder->calls)->toBe(1);
});

it('reserves learn id before generating questions (seed by learnId)', function () {
    [$action, $learns, $lessons, , , $questionsBuilder] = makeStartLearnAction();
    $lessons->add(10);

    $seenSeedLearnId = null;
    $questionsBuilder->withFactory(function (\App\Application\Learn\DTOs\LearnSeed $seed) use (&$seenSeedLearnId) {
        $seenSeedLearnId = $seed->learnId->value;

        return domainQuestions();
    });

    $learn = $action(new StartLearnData(new UserId(1), new LessonId(10)));

    // learnId, который сид задач использовал, совпадает с id сохранённого прохождения.
    expect($seenSeedLearnId)->toBe($learn->id()->value);
});
