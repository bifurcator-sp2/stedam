<?php

declare(strict_types=1);

use App\Application\Learn\Actions\ArchiveOldLearnsAction;
use App\Application\Learn\DTOs\ArchiveCriteria;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Enums\LearnStatus;
use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LearnAnswers;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\ScheduleDate;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Domain\Learn\ValueObjects\UserId;
use Tests\Support\Fakes\InMemoryLearnRepository;

/**
 * Создаёт graded-прохождение, завершённое в $completedAt.
 */
function seedGraded(InMemoryLearnRepository $repo, DateTimeImmutable $completedAt, int $userId = 5, int $lessonId = 10): Learn
{
    $start = $completedAt->modify('-1 hour');
    $learn = Learn::startNow(
        new UserId($userId),
        new LessonId($lessonId),
        SnapshotHash::fromContent("lesson-{$lessonId}"),
        domainQuestions(),
        $start,
    );
    $learn->markDone(LearnAnswers::empty(), $completedAt);
    $learn->markGraded(new Grade(80.0), null, $completedAt);

    return $repo->save($learn);
}

/**
 * Создаёт created/scheduled прохождение с заданным created_at.
 */
function seedOpen(InMemoryLearnRepository $repo, LearnStatus $status, DateTimeImmutable $createdAt, int $userId = 5): Learn
{
    $learn = $status === LearnStatus::Scheduled
        ? Learn::schedule(new UserId($userId), new LessonId(10), new ScheduleDate($createdAt->modify('+10 days')), $createdAt)
        : Learn::assign(new UserId($userId), new LessonId(10), $createdAt);

    return $repo->save($learn);
}

function archiveCriteria(array $days, DateTimeImmutable $now): ArchiveCriteria
{
    return ArchiveCriteria::fromConfig(['after_days' => $days], $now);
}

it('archives graded learns older than threshold, by complete_date', function () {
    $repo = new InMemoryLearnRepository();
    $now = new DateTimeImmutable('2025-06-01 00:00:00 UTC');

    $old = seedGraded($repo, $now->modify('-400 days'));   // старше 365 → архив
    $fresh = seedGraded($repo, $now->modify('-100 days')); // младше 365 → остаётся

    $result = (new ArchiveOldLearnsAction($repo))(
        archiveCriteria(['graded' => 365], $now),
    );

    expect($result->total())->toBe(1)
        ->and($repo->getById($old->id())->isArchived())->toBeTrue()
        ->and($repo->getById($fresh->id())->isArchived())->toBeFalse();
});

it('does not archive done/in_progress (no criteria for them)', function () {
    $repo = new InMemoryLearnRepository();
    $now = new DateTimeImmutable('2025-06-01 00:00:00 UTC');

    // done: сдан, ждёт оценки — не должен архивироваться
    $done = Learn::startNow(new UserId(5), new LessonId(10), SnapshotHash::fromContent('l10'), domainQuestions(), $now->modify('-400 days'));
    $done->markDone(LearnAnswers::empty(), $now->modify('-399 days'));
    $done = $repo->save($done);

    $result = (new ArchiveOldLearnsAction($repo))(
        archiveCriteria(['graded' => 365], $now), // критериев для done нет
    );

    expect($result->total())->toBe(0)
        ->and($repo->getById($done->id())->isArchived())->toBeFalse();
});

it('archives created/scheduled by created_at', function () {
    $repo = new InMemoryLearnRepository();
    $now = new DateTimeImmutable('2025-06-01 00:00:00 UTC');

    $oldCreated = seedOpen($repo, LearnStatus::Created, $now->modify('-100 days'));
    $oldScheduled = seedOpen($repo, LearnStatus::Scheduled, $now->modify('-100 days'));
    $fresh = seedOpen($repo, LearnStatus::Created, $now->modify('-10 days'));

    $result = (new ArchiveOldLearnsAction($repo))(
        archiveCriteria(['created' => 90, 'scheduled' => 90], $now),
    );

    expect($result->total())->toBe(2)
        ->and($repo->getById($oldCreated->id())->isArchived())->toBeTrue()
        ->and($repo->getById($oldScheduled->id())->isArchived())->toBeTrue()
        ->and($repo->getById($fresh->id())->isArchived())->toBeFalse();
});

it('is idempotent: second run does not touch already archived', function () {
    $repo = new InMemoryLearnRepository();
    $now = new DateTimeImmutable('2025-06-01 00:00:00 UTC');

    seedGraded($repo, $now->modify('-400 days'));
    $action = new ArchiveOldLearnsAction($repo);

    $first = $action(archiveCriteria(['graded' => 365], $now));
    $second = $action(archiveCriteria(['graded' => 365], $now));

    expect($first->total())->toBe(1)
        ->and($second->total())->toBe(0);
});

it('dry-run counts candidates but does not modify', function () {
    $repo = new InMemoryLearnRepository();
    $now = new DateTimeImmutable('2025-06-01 00:00:00 UTC');

    $old = seedGraded($repo, $now->modify('-400 days'));

    $result = (new ArchiveOldLearnsAction($repo))(
        archiveCriteria(['graded' => 365], $now),
        dryRun: true,
    );

    expect($result->total())->toBe(1)
        ->and($result->dryRun)->toBeTrue()
        ->and($repo->getById($old->id())->isArchived())->toBeFalse();
});

it('criteria override narrows statuses and overrides days', function () {
    $now = new DateTimeImmutable('2025-06-01 00:00:00 UTC');
    $base = archiveCriteria(['graded' => 365, 'created' => 90], $now);

    $narrowed = $base->withOverrides([LearnStatus::Graded], 30);

    expect($narrowed->daysByStatus)->toBe(['graded' => 30]);
});
