<?php

declare(strict_types=1);

use App\Application\Learn\Actions\StartLearnAction;
use App\Application\Learn\DTOs\StartLearnData;
use App\Application\Learn\Exceptions\LessonNotFound;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\UserId;
use Tests\Support\Fakes\FakeLessonRepository;
use Tests\Support\Fakes\FakeSnapshotResolver;
use Tests\Support\Fakes\FakeStatusLogger;
use Tests\Support\Fakes\InMemoryLearnRepository;
use Tests\Support\FrozenClock;

/**
 * Хелпер, собирающий Action со всеми фейками.
 * Возвращает массив: [action, learns, lessons, snapshots, logger]
 */
function makeStartLearnAction(
    ?FakeLessonRepository $lessons = null,
    ?FakeSnapshotResolver $snapshots = null,
    ?FakeStatusLogger $logger = null,
): array {
    $learns = new InMemoryLearnRepository();
    $lessons ??= new FakeLessonRepository();
    $snapshots ??= new FakeSnapshotResolver();
    $logger ??= new FakeStatusLogger();
    $clock = new FrozenClock(new DateTimeImmutable('2025-01-01 12:00:00 UTC'));

    $action = new StartLearnAction($learns, $lessons, $snapshots, $logger, $clock);

    return [$action, $learns, $lessons, $snapshots, $logger];
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
