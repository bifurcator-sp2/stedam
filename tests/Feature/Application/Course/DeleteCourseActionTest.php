<?php

declare(strict_types=1);

use App\Application\Course\Actions\DeleteCourseAction;
use App\Application\Course\Actions\UnassignCourseAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Domain\Course\Entities\CourseAssignment;
use App\Domain\Course\Events\CourseAssignmentRemoved;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\CourseScene;

function makeDeleteAction(CourseScene $scene): DeleteCourseAction
{
    return new DeleteCourseAction(
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
}

/**
 * @param list<int> $studentIds
 */
function assignStudents(CourseScene $scene, CourseId $courseId, array $studentIds): void
{
    foreach ($studentIds as $studentId) {
        $scene->assignments->add(
            CourseAssignment::create(
                courseId: $courseId,
                studentId: new UserId($studentId),
                assignedBy: new UserId(1),
                now: new DateTimeImmutable('2025-01-01 12:00:00 UTC'),
            ),
        );
    }
}

it('удаляет курс без назначений', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);

    makeDeleteAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
    );

    expect($scene->courses->findById($course->id()))->toBeNull()
        ->and($scene->courses->deleteCalls)->toBe(1);
});

it('удаляет курс вместе со всеми назначениями', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);
    assignStudents($scene, $course->id(), [5, 6, 7]);

    makeDeleteAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
    );

    expect($scene->courses->findById($course->id()))->toBeNull()
        ->and($scene->assignments->count())->toBe(0)
        ->and($scene->events->countOf(CourseAssignmentRemoved::class))->toBe(3);
});

it('удаляет через ОДНУ транзакцию, а не по одной на ученика', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);
    assignStudents($scene, $course->id(), [5, 6, 7, 8, 9]);

    makeDeleteAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
    );

    // Главная проверка: 5 учеников — но открыта ровно одна транзакция.
    // Если бы DeleteCourseAction звал UnassignCourseAction::execute() в цикле,
    // здесь было бы 6 (1 внешняя + 5 вложенных).
    expect($scene->transaction->calls)->toBe(1);
});

it('публикует событие снятия на каждое назначение', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);
    assignStudents($scene, $course->id(), [5, 6]);

    makeDeleteAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
    );

    $studentIds = [];

    foreach ($scene->events->dispatchedEvents() as $event) {
        if ($event instanceof CourseAssignmentRemoved) {
            $studentIds[] = $event->studentId->value;
        }
    }

    sort($studentIds);

    expect($studentIds)->toBe([5, 6]);
});

it('отказывает без прав на редактирование и ничего не трогает', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]]);
    assignStudents($scene, $course->id(), [5]);

    expect(
        fn () => makeDeleteAction($scene)->execute(
            CourseViewerContext::student(new UserId(7)),
            $course->id(),
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->courses->findById($course->id()))->not->toBeNull()
        ->and($scene->assignments->count())->toBe(1)
        ->and($scene->courses->deleteCalls)->toBe(0);
});

it('отказывает чужому учителю', function () {
    $scene = new CourseScene();
    // Курс принадлежит учителю 1.
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]], ownerId: new UserId(1));

    expect(
        fn () => makeDeleteAction($scene)->execute(
            CourseViewerContext::teacher(new UserId(42)),
            $course->id(),
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->courses->findById($course->id()))->not->toBeNull();
});

it('админ НЕ удаляет чужой авторский курс', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]], ownerId: new UserId(1));

    // §13.1: удаление = право владения. Админ правит только системные
    // и свои курсы, чужой авторский ему недоступен.
    expect(
        fn () => makeDeleteAction($scene)->execute(
            CourseViewerContext::admin(new UserId(42)),
            $course->id(),
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->courses->findById($course->id()))->not->toBeNull();
});

it('админ удаляет системный курс', function () {
    $scene = new CourseScene();
    $course = $scene->savedSystemCourse(['Тема 1' => [10]]);

    makeDeleteAction($scene)->execute(
        CourseViewerContext::admin(new UserId(42)),
        $course->id(),
    );

    expect($scene->courses->findById($course->id()))->toBeNull();
});

it('учитель не удаляет системный курс', function () {
    $scene = new CourseScene();
    $course = $scene->savedSystemCourse(['Тема 1' => [10]]);

    expect(
        fn () => makeDeleteAction($scene)->execute(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
        ),
    )->toThrow(CourseAccessDenied::class);
});

it('отказывает, если курс не найден', function () {
    $scene = new CourseScene();

    expect(
        fn () => makeDeleteAction($scene)->execute(
            CourseViewerContext::admin(new UserId(1)),
            new CourseId(999),
        ),
    )->toThrow(CourseNotFound::class);

    expect($scene->courses->deleteCalls)->toBe(0);
});
