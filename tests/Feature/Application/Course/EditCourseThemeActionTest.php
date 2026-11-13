<?php

declare(strict_types=1);

use App\Application\Course\Actions\EditCourseThemeAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Events\CourseChanged;
use App\Domain\Course\Exceptions\InvalidCourseOrder;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseThemeId;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\CourseScene;

function makeThemeAction(CourseScene $scene): EditCourseThemeAction
{
    return new EditCourseThemeAction($scene->courses, $scene->events);
}

/**
 * @return list<string>
 */
function themeTitles(Course $course): array
{
    return array_map(static fn ($theme) => $theme->title(), $course->themes());
}

it('добавляет тему в конец', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Тема 1' => [10]], ownerId: new UserId(1));

    $updated = makeThemeAction($scene)->add(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        'Новая тема',
    );

    expect(themeTitles($updated))->toBe(['Тема 1', 'Новая тема'])
        ->and($scene->events->countOf(CourseChanged::class))->toBe(1);
});

it('переименовывает тему', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['Старое' => [10]], ownerId: new UserId(1));
    $themeId = $course->themes()[0]->id();

    $updated = makeThemeAction($scene)->rename(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        $themeId,
        'Новое',
    );

    expect(themeTitles($updated))->toBe(['Новое']);
});

it('удаляет тему вместе с уроками', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons([
        'Тема 1' => [10, 20],
        'Тема 2' => [30],
    ], ownerId: new UserId(1));
    $themeId = $course->themes()[0]->id();

    $updated = makeThemeAction($scene)->remove(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        $themeId,
    );

    expect(themeTitles($updated))->toBe(['Тема 2'])
        ->and($updated->countLessons())->toBe(1);
});

it('переставляет темы', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons([
        'A' => [10],
        'B' => [20],
    ], ownerId: new UserId(1));
    $themeB = $course->themes()[1]->id();

    // Позиции 1-based: B → в начало.
    $updated = makeThemeAction($scene)->move(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        $themeB,
        1,
    );

    expect(themeTitles($updated))->toBe(['B', 'A']);
});

it('отказывает на позиции вне диапазона', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['A' => [10]], ownerId: new UserId(1));

    expect(
        fn () => makeThemeAction($scene)->move(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
            $course->themes()[0]->id(),
            0,
        ),
    )->toThrow(InvalidCourseOrder::class);
});

it('каждая операция публикует CourseChanged', function (string $operation) {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['A' => [10], 'B' => [20]], ownerId: new UserId(1));
    $viewer = CourseViewerContext::teacher(new UserId(1));
    $action = makeThemeAction($scene);
    $themeId = $course->themes()[0]->id();

    match ($operation) {
        'add' => $action->add($viewer, $course->id(), 'C'),
        'rename' => $action->rename($viewer, $course->id(), $themeId, 'A2'),
        'remove' => $action->remove($viewer, $course->id(), $themeId),
        'move' => $action->move($viewer, $course->id(), $themeId, 2),
    };

    expect($scene->events->countOf(CourseChanged::class))->toBe(1);
})->with(['add', 'rename', 'remove', 'move']);

it('отказывает без прав редактирования', function () {
    $scene = new CourseScene();
    $course = $scene->savedCourseWithLessons(['A' => [10]], ownerId: new UserId(1));

    expect(
        fn () => makeThemeAction($scene)->add(
            CourseViewerContext::student(new UserId(9)),
            $course->id(),
            'Взлом',
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->events->countOf(CourseChanged::class))->toBe(0);
});

it('отказывает, если курс не найден', function () {
    $scene = new CourseScene();

    expect(
        fn () => makeThemeAction($scene)->add(
            CourseViewerContext::admin(new UserId(1)),
            new CourseId(999),
            'X',
        ),
    )->toThrow(CourseNotFound::class);
});
