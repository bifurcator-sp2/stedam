<?php

declare(strict_types=1);

use App\Application\Course\Actions\EditCourseLessonAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Domain\Course\Events\CourseChanged;
use App\Domain\Course\Exceptions\CourseLessonNotInTheme;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\LessonTypeId;
use App\Domain\Course\ValueObjects\TimeGap;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\CourseScene;

function makeLessonAction(CourseScene $scene): EditCourseLessonAction
{
    return new EditCourseLessonAction(
        $scene->courses,
        $scene->lessons,
        $scene->events,
    );
}

/** @return array{0: LessonId[], 1: \App\Domain\Course\ValueObjects\CourseThemeId} */
function lessonIdsOfFirstTheme(\App\Domain\Course\Entities\Course $course): array
{
    $theme = $course->themes()[0];

    return [
        array_map(static fn ($lesson) => $lesson->lessonId(), $theme->lessons()),
        $theme->id(),
    ];
}

it('добавляет урок в тему', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]], ownerId: new UserId(1));
    $scene->lessons->addLesson(20);

    [$lessonIds, $themeId] = lessonIdsOfFirstTheme($course);

    $updated = makeLessonAction($scene)->add(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        $themeId,
        new LessonId(20),
    );

    [$after] = lessonIdsOfFirstTheme($updated);

    expect($after)->toHaveCount(2)
        ->and($after[1]->value)->toBe(20)
        ->and($scene->events->countOf(CourseChanged::class))->toBe(1);
});

it('отказывает добавить несуществующий урок', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]], ownerId: new UserId(1));
    [, $themeId] = lessonIdsOfFirstTheme($course);

    expect(
        fn () => makeLessonAction($scene)->add(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
            $themeId,
            new LessonId(999), // в читателе уроков не зарегистрирован
        ),
    )->toThrow(CourseLessonNotInTheme::class);

    expect($scene->events->countOf(CourseChanged::class))->toBe(0);
});

it('добавляет урок с типом и разрывом', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]], ownerId: new UserId(1));
    $scene->lessons->addLesson(20);
    [, $themeId] = lessonIdsOfFirstTheme($course);

    makeLessonAction($scene)->add(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        $themeId,
        new LessonId(20),
        typeId: new LessonTypeId(3),
        timeGap: new TimeGap(5),
    );

    $saved = $scene->courses->findById($course->id());
    $added = $saved->themes()[0]->lessons()[1];

    expect($added->typeId()?->value)->toBe(3)
        ->and($added->timeGap()->hours)->toBe(5);
});

it('удаляет урок из темы', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20]], ownerId: new UserId(1));
    [, $themeId] = lessonIdsOfFirstTheme($course);

    $updated = makeLessonAction($scene)->remove(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        $themeId,
        new LessonId(10),
    );

    [$after] = lessonIdsOfFirstTheme($updated);

    expect($after)->toHaveCount(1)
        ->and($after[0]->value)->toBe(20)
        ->and($scene->events->countOf(CourseChanged::class))->toBe(1);
});

it('переставляет урок внутри темы', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20, 30]], ownerId: new UserId(1));
    [, $themeId] = lessonIdsOfFirstTheme($course);

    // Позиции 1-based (как order в domain): урок 30 → в начало.
    $updated = makeLessonAction($scene)->move(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        $themeId,
        new LessonId(30),
        1,
    );

    [$after] = lessonIdsOfFirstTheme($updated);

    expect(array_map(static fn ($id) => $id->value, $after))->toBe([30, 10, 20]);
});

it('отказывает на позиции вне диапазона', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20]], ownerId: new UserId(1));
    [, $themeId] = lessonIdsOfFirstTheme($course);

    // 0 — невалидно: позиции начинаются с 1.
    expect(
        fn () => makeLessonAction($scene)->move(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
            $themeId,
            new LessonId(10),
            0,
        ),
    )->toThrow(\App\Domain\Course\Exceptions\InvalidCourseOrder::class);
});

it('меняет тип урока', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]], ownerId: new UserId(1));
    [, $themeId] = lessonIdsOfFirstTheme($course);

    $updated = makeLessonAction($scene)->changeType(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        $themeId,
        new LessonId(10),
        new LessonTypeId(7),
    );

    expect($updated->themes()[0]->lessons()[0]->typeId()?->value)->toBe(7);
});

it('меняет разрыв между уроками', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]], ownerId: new UserId(1));
    [, $themeId] = lessonIdsOfFirstTheme($course);

    $updated = makeLessonAction($scene)->changeTimeGap(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        $themeId,
        new LessonId(10),
        new TimeGap(8),
    );

    expect($updated->themes()[0]->lessons()[0]->timeGap()->hours)->toBe(8);
});

it('каждая операция публикует CourseChanged', function (string $operation) {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10, 20]], ownerId: new UserId(1));
    $scene->lessons->addLesson(30);
    [, $themeId] = lessonIdsOfFirstTheme($course);
    $viewer = CourseViewerContext::teacher(new UserId(1));
    $action = makeLessonAction($scene);

    match ($operation) {
        'add' => $action->add($viewer, $course->id(), $themeId, new LessonId(30)),
        'remove' => $action->remove($viewer, $course->id(), $themeId, new LessonId(20)),
        'move' => $action->move($viewer, $course->id(), $themeId, new LessonId(10), 1),
        'changeType' => $action->changeType($viewer, $course->id(), $themeId, new LessonId(10), new LessonTypeId(2)),
        'changeTimeGap' => $action->changeTimeGap($viewer, $course->id(), $themeId, new LessonId(10), new TimeGap(4)),
    };

    expect($scene->events->countOf(CourseChanged::class))->toBe(1);
})->with(['add', 'remove', 'move', 'changeType', 'changeTimeGap']);

it('отказывает без прав редактирования', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]], ownerId: new UserId(1));
    $scene->lessons->addLesson(20);
    [, $themeId] = lessonIdsOfFirstTheme($course);

    expect(
        fn () => makeLessonAction($scene)->add(
            CourseViewerContext::student(new UserId(9)),
            $course->id(),
            $themeId,
            new LessonId(20),
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->events->countOf(CourseChanged::class))->toBe(0);
});

it('отказывает, если курс не найден', function () {
    $scene = new CourseScene();
    $scene->lessons->addLesson(20);

    expect(
        fn () => makeLessonAction($scene)->add(
            CourseViewerContext::admin(new UserId(1)),
            new CourseId(999),
            new \App\Domain\Course\ValueObjects\CourseThemeId(1),
            new LessonId(20),
        ),
    )->toThrow(CourseNotFound::class);
});
