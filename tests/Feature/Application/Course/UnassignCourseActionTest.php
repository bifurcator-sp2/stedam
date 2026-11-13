<?php

declare(strict_types=1);

use App\Application\Course\Actions\AssignCourseAction;
use App\Application\Course\Actions\UnassignCourseAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Domain\Course\Events\CourseAssignmentCreated;
use App\Domain\Course\Events\CourseAssignmentRemoved;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\CourseScene;

function makeUnassignAction(CourseScene $scene): UnassignCourseAction
{
    return new UnassignCourseAction(
        $scene->courses,
        $scene->assignments,
        $scene->transaction,
        $scene->events,
    );
}

/**
 * Сцена: курс + одно назначение ученику 5.
 *
 * @return array{CourseScene, \App\Domain\Course\Entities\Course}
 */
function sceneWithAssignment(): array
{
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20]]);

    $scene->assignments->add(
        \App\Domain\Course\Entities\CourseAssignment::create(
            courseId: $course->id(),
            studentId: new UserId(5),
            assignedBy: new UserId(1),
            now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
        ),
    );

    return [$scene, $course];
}

it('снимает назначение и публикует событие', function () {
    [$scene, $course] = sceneWithAssignment();

    makeUnassignAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        new UserId(5),
    );

    expect($scene->assignments->count())->toBe(0)
        ->and($scene->events->countOf(CourseAssignmentRemoved::class))->toBe(1);
});

it('публикует событие с правильными идентификаторами', function () {
    [$scene, $course] = sceneWithAssignment();

    makeUnassignAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        new UserId(5),
    );

    /** @var CourseAssignmentRemoved $event */
    $event = $scene->events->lastOf(CourseAssignmentRemoved::class);

    expect($event->courseId->value)->toBe($course->id()->value)
        ->and($event->studentId->value)->toBe(5);
});

it('выполняет работу внутри транзакции', function () {
    [$scene, $course] = sceneWithAssignment();

    makeUnassignAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        new UserId(5),
    );

    expect($scene->transaction->calls)->toBe(1)
        ->and($scene->transaction->committed)->toBeTrue();
});

it('отказывает без прав на назначение', function () {
    [$scene, $course] = sceneWithAssignment();

    expect(
        fn () => makeUnassignAction($scene)->execute(
            CourseViewerContext::student(new UserId(5)),
            $course->id(),
            new UserId(5),
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->assignments->count())->toBe(1)
        ->and($scene->events->countOf(CourseAssignmentRemoved::class))->toBe(0);
});

it('отказывает, если курс не найден', function () {
    $scene = new CourseScene();

    expect(
        fn () => makeUnassignAction($scene)->execute(
            CourseViewerContext::admin(new UserId(1)),
            new CourseId(999),
            new UserId(5),
        ),
    )->toThrow(CourseNotFound::class);
});

it('отказывает, если назначения нет', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

    expect(
        fn () => makeUnassignAction($scene)->execute(
            CourseViewerContext::admin(new UserId(1)),
            $course->id(),
            new UserId(5),
        ),
    )->toThrow(CourseNotFound::class);

    expect($scene->events->countOf(CourseAssignmentRemoved::class))->toBe(0);
});

it('не трогает назначения других учеников', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

    $create = \App\Domain\Course\Entities\CourseAssignment::create(
        courseId: $course->id(),
        studentId: new UserId(5),
        assignedBy: new UserId(1),
        now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
    );

    $scene->assignments->add($create);
    $scene->assignments->add(
        \App\Domain\Course\Entities\CourseAssignment::create(
            courseId: $course->id(),
            studentId: new UserId(6),
            assignedBy: new UserId(1),
            now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
        ),
    );

    makeUnassignAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        new UserId(5),
    );

    expect($scene->assignments->count())->toBe(1)
        ->and($scene->assignments->exists($course->id(), new UserId(6)))->toBeTrue()
        ->and($scene->assignments->exists($course->id(), new UserId(5)))->toBeFalse();
});

it('removeAssignment делает то же без открытия транзакции', function () {
    [$scene, $course] = sceneWithAssignment();

    /** @var \App\Domain\Course\Entities\CourseAssignment $assignment */
    $assignment = $scene->assignments->listByCourse($course->id())[0];
    $transactionsBefore = $scene->transaction->calls;

    makeUnassignAction($scene)->removeAssignment(
        $assignment->id(),
        $course->id(),
        new UserId(5),
    );

    expect($scene->assignments->count())->toBe(0)
        ->and($scene->events->countOf(CourseAssignmentRemoved::class))->toBe(1)
        // Главное свойство: своей транзакции нет — вызывающий уже внутри.
        ->and($scene->transaction->calls)->toBe($transactionsBefore);
});
