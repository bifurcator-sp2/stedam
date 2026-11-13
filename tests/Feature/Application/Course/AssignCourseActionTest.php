<?php

declare(strict_types=1);

use App\Application\Course\Actions\AssignCourseAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Domain\Course\Events\CourseAssignmentCreated;
use App\Domain\Course\Exceptions\CourseAlreadyAssigned;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\CourseScene;

function makeAssignAction(CourseScene $scene): AssignCourseAction
{
    return new AssignCourseAction(
        $scene->courses,
        $scene->assignments,
        $scene->transaction,
        $scene->events,
    );
}

it('создаёт назначение и публикует событие', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20]]);
    $action = makeAssignAction($scene);

    $assignment = $action->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        new UserId(5),
    );

    expect($assignment->id())->not->toBeNull()
        ->and($assignment->studentId()->value)->toBe(5)
        ->and($assignment->courseId()->value)->toBe($course->id()->value)
        ->and($scene->events->countOf(CourseAssignmentCreated::class))->toBe(1);
});

it('публикует событие с правильными идентификаторами', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);
    $action = makeAssignAction($scene);

    $action->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        new UserId(5),
    );

    /** @var CourseAssignmentCreated $event */
    $event = $scene->events->lastOf(CourseAssignmentCreated::class);

    expect($event->courseId->value)->toBe($course->id()->value)
        ->and($event->studentId->value)->toBe(5)
        ->and($event->assignedBy->value)->toBe(1);
});

it('выполняет работу внутри транзакции', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

    makeAssignAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        new UserId(5),
    );

    expect($scene->transaction->calls)->toBe(1)
        ->and($scene->transaction->committed)->toBeTrue()
        ->and($scene->transaction->rolledBack)->toBeFalse();
});

it('отказывает ученику и родителю в назначении', function (CourseViewerContext $viewer) {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

    expect(
        fn () => makeAssignAction($scene)->execute(
            $viewer,
            $course->id(),
            new UserId(5),
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->assignments->count())->toBe(0)
        ->and($scene->events->countOf(CourseAssignmentCreated::class))->toBe(0);
})->with([
    'ученик' => fn () => CourseViewerContext::student(new UserId(9)),
    'родитель' => fn () => CourseViewerContext::parent(new UserId(9), []),
    'гость' => fn () => CourseViewerContext::guest(),
]);

it('отказывает, если курс не найден', function () {
    $scene = new CourseScene();

    expect(
        fn () => makeAssignAction($scene)->execute(
            CourseViewerContext::admin(new UserId(1)),
            new CourseId(999),
            new UserId(5),
        ),
    )->toThrow(CourseNotFound::class);

    expect($scene->assignments->count())->toBe(0);
});

it('запрещает повторное назначение того же курса ученику', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);
    $action = makeAssignAction($scene);
    $viewer = CourseViewerContext::teacher(new UserId(1));

    $action->execute($viewer, $course->id(), new UserId(5));

    expect(fn () => $action->execute($viewer, $course->id(), new UserId(5)))
        ->toThrow(CourseAlreadyAssigned::class);

    // Первое назначение осталось, второе не создано.
    expect($scene->assignments->count())->toBe(1)
        ->and($scene->events->countOf(CourseAssignmentCreated::class))->toBe(1);
});

it('позволяет разным ученикам один и тот же курс', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);
    $action = makeAssignAction($scene);
    $viewer = CourseViewerContext::teacher(new UserId(1));

    $action->execute($viewer, $course->id(), new UserId(5));
    $action->execute($viewer, $course->id(), new UserId(6));

    expect($scene->assignments->count())->toBe(2)
        ->and($scene->events->countOf(CourseAssignmentCreated::class))->toBe(2);
});

it('не публикует событие и не сохраняет при отказе', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

    try {
        makeAssignAction($scene)->execute(
            CourseViewerContext::student(new UserId(9)),
            $course->id(),
            new UserId(5),
        );
    } catch (CourseAccessDenied) {
        // ожидаемо
    }

    expect($scene->events->dispatchedEvents())->toBe([])
        ->and($scene->assignments->count())->toBe(0)
        ->and($scene->transaction->rolledBack)->toBeTrue();
});
