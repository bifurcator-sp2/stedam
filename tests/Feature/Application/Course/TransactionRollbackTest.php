<?php

declare(strict_types=1);

use App\Application\Course\Actions\DeleteCourseAction;
use App\Application\Course\Actions\UnassignCourseAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Domain\Course\Entities\CourseAssignment;
use App\Domain\Course\Events\CourseAssignmentRemoved;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\CourseScene;

/**
 * Откат: если операция падает посередине, ничего не должно сохраниться
 * частично.
 */
function addAssignment(CourseScene $scene, \App\Domain\Course\ValueObjects\CourseId $courseId, int $studentId): void
{
    $scene->assignments->add(CourseAssignment::create(
        courseId: $courseId,
        studentId: new UserId($studentId),
        assignedBy: new UserId(1),
        now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    ));
}

/**
 * Снять копию состояния назначений — возвращается к ней при откате.
 *
 * @return Closure(): void
 */
function snapshotAssignments(CourseScene $scene): Closure
{
    /** @var list<CourseAssignment> $before */
    $before = [];

    // listByCourse по каждому курсу — репозиторий фейковый, порядок стабилен.
    foreach ($scene->assignments->all() as $assignment) {
        $before[] = $assignment;
    }

    return function () use ($scene, $before): void {
        $scene->assignments->replaceAll($before);
    };
}

it('при падении транзакции курс не удаляется', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20]]);
    addAssignment($scene, $course->id(), 5);

    $scene->transaction->onRollback(snapshotAssignments($scene));
    $scene->transaction->failOnCall = 1;

    $action = new DeleteCourseAction(
        $scene->courses,
        $scene->assignments,
        new UnassignCourseAction(
            $scene->courses,
            $scene->assignments,
            $scene->transaction,
            $scene->events,
        ),
        $scene->transaction,
    );

    expect(
        fn () => $action->execute(CourseViewerContext::teacher(new UserId(1)), $course->id()),
    )->toThrow(RuntimeException::class);

    expect($scene->transaction->rolledBack)->toBeTrue()
        ->and($scene->transaction->committed)->toBeFalse()
        ->and($scene->courses->findById($course->id()))->not->toBeNull()
        ->and($scene->assignments->count())->toBe(1);
});

it('при падении транзакции назначение не снимается', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);
    addAssignment($scene, $course->id(), 5);

    $scene->transaction->onRollback(snapshotAssignments($scene));
    $scene->transaction->failOnCall = 1;

    $action = new UnassignCourseAction(
        $scene->courses,
        $scene->assignments,
        $scene->transaction,
        $scene->events,
    );

    expect(
        fn () => $action->execute(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
            new UserId(5),
        ),
    )->toThrow(RuntimeException::class);

    expect($scene->assignments->count())->toBe(1);
});

it('событие не публикуется, если транзакция откатилась', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);
    addAssignment($scene, $course->id(), 5);

    $scene->transaction->onRollback(snapshotAssignments($scene));
    $scene->transaction->failOnCall = 1;

    $action = new UnassignCourseAction(
        $scene->courses,
        $scene->assignments,
        $scene->transaction,
        $scene->events,
    );

    try {
        $action->execute(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
            new UserId(5),
        );
    } catch (RuntimeException) {
        // ожидаемо
    }

    // Снятие не состоялось — слушатели не должны были узнать о нём.
    expect($scene->events->countOf(CourseAssignmentRemoved::class))->toBe(0);
});
