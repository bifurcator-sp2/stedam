<?php

declare(strict_types=1);

use App\Application\Learn\Actions\GetLearnAction;
use App\Application\Learn\Actions\ListLearnsAction;
use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\Context\ViewerContext;
use App\Application\Learn\Context\ViewerRole;
use App\Application\Learn\DTOs\LearnListFilter;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Domain\Learn\ValueObjects\UserId;
use Tests\Support\Fakes\InMemoryLearnRepository;
use Tests\Support\Fakes\StubContextResolver;

/**
 * Стартует и сохраняет прохождение.
 */
function makeLearn(InMemoryLearnRepository $repo, int $userId, int $lessonId = 10): Learn
{
    $now = new DateTimeImmutable('2025-01-01 12:00:00 UTC');
    $learn = Learn::startNow(
        userId: new UserId($userId),
        lessonId: new LessonId($lessonId),
        snapshot: SnapshotHash::fromContent("lesson-{$lessonId}"),
        questions: domainQuestions(),
        now: $now,
    );

    return $repo->save($learn);
}

it('get returns learn within scope', function () {
    $repo = new InMemoryLearnRepository();
    $learn = makeLearn($repo, 5);

    $resolver = new StubContextResolver([
        1 => new ViewerContext(
            viewerId: 1,
            roles: [ViewerRole::Teacher],
            visibleUserIds: [1, 5],
            canGrade: true, canAssign: true, canSchedule: true,
            canCancel: true, canStart: false, canSave: false,
        ),
    ]);

    $action = new GetLearnAction($repo, $resolver);
    $result = $action($learn->id(), new UserId(1));

    expect($result->id()->value)->toBe($learn->id()->value);
});

it('get denies learn outside scope as not found', function () {
    $repo = new InMemoryLearnRepository();
    $learn = makeLearn($repo, 5);

    // viewer 2 видит только себя
    $resolver = new StubContextResolver([
        2 => ViewerContext::readOnly(2, [ViewerRole::Student], [2]),
    ]);

    $action = new GetLearnAction($repo, $resolver);

    expect(fn () => $action($learn->id(), new UserId(2)))
        ->toThrow(LearnNotFound::class);
});

it('list returns only learns of visible users, newest first, paginated', function () {
    $repo = new InMemoryLearnRepository();
    $mine1 = makeLearn($repo, 5, 10);
    $mine2 = makeLearn($repo, 5, 20);
    $stranger = makeLearn($repo, 9, 30);

    $resolver = new StubContextResolver([
        1 => ViewerContext::readOnly(1, [ViewerRole::Teacher], [1, 5]),
    ]);

    $action = new ListLearnsAction($repo, $resolver);
    $page = $action(new UserId(1), LearnListFilter::default());

    expect($page->total)->toBe(2)
        ->and(array_map(fn (Learn $l) => $l->id()->value, $page->items))
        ->toBe([$mine2->id()->value, $mine1->id()->value]) // newest first
        ->and(array_map(fn (Learn $l) => $l->id()->value, $page->items))
        ->not->toContain($stranger->id()->value);
});

it('list filters by status and lesson', function () {
    $repo = new InMemoryLearnRepository();
    makeLearn($repo, 5, 10); // in_progress
    makeLearn($repo, 5, 20); // in_progress

    $resolver = new StubContextResolver([
        1 => ViewerContext::readOnly(1, [ViewerRole::Teacher], [1, 5]),
    ]);
    $action = new ListLearnsAction($repo, $resolver);

    // по уроку 10 — одна запись
    $byLesson = $action(new UserId(1), new LearnListFilter(lessonId: new LessonId(10)));
    expect($byLesson->total)->toBe(1);

    // по статусу done — ноль (все in_progress)
    $byStatus = $action(new UserId(1), new LearnListFilter(statuses: [LearnStatus::Done]));
    expect($byStatus->total)->toBe(0);

    // по статусу in_progress — две
    $byInProgress = $action(new UserId(1), new LearnListFilter(statuses: [LearnStatus::InProgress]));
    expect($byInProgress->total)->toBe(2);
});

it('list respects per-page pagination', function () {
    $repo = new InMemoryLearnRepository();
    for ($i = 0; $i < 5; $i++) {
        makeLearn($repo, 5, 10 + $i);
    }

    $resolver = new StubContextResolver([
        1 => ViewerContext::readOnly(1, [ViewerRole::Teacher], [1, 5]),
    ]);
    $action = new ListLearnsAction($repo, $resolver);

    $page1 = $action(new UserId(1), new LearnListFilter(page: 1, perPage: 2));
    expect($page1->total)->toBe(5)
        ->and($page1->items)->toHaveCount(2)
        ->and($page1->hasMorePages())->toBeTrue();

    $page3 = $action(new UserId(1), new LearnListFilter(page: 3, perPage: 2));
    expect($page3->items)->toHaveCount(1)
        ->and($page3->hasMorePages())->toBeFalse();
});
