<?php

declare(strict_types=1);

use App\Application\Course\Actions\EditCourseHeaderAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Entities\CourseLesson;
use App\Domain\Course\Entities\CourseTheme;
use App\Domain\Course\Exceptions\CourseLocaleChangeDenied;
use App\Domain\Course\Exceptions\CourseTaxonomyChangeDenied;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\NextLessonThreshold;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\TimeGap;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\CourseScene;

function makeEditHeaderAction(CourseScene $scene): EditCourseHeaderAction
{
    return new EditCourseHeaderAction($scene->courses, $scene->lessons);
}

/**
 * Курс с указанным составом уроков и владельцем.
 *
 * @param list<int> $lessonIds
 */
function savedCourse(
    CourseScene $scene,
    array $lessonIds = [],
    ?UserId $ownerId = null,
    ?TaxonomyId $taxId = null,
): Course {
    if ($lessonIds === []) {
        $courseId = new CourseId($scene->nextCourseId++);

        return $scene->courses->add(Course::restore(
            id: $courseId,
            userId: $ownerId,
            title: new CourseTitle('Курс'),
            description: new CourseDescription('Описание курса для теста'),
            locale: new Locale('ru'),
            nextLessonThreshold: new NextLessonThreshold(0.6),
            taxId: $taxId,
            themes: [],
            updatedAt: null,
        ));
    }

    return $scene->savedCourseWithLessons(
        ['Тема 1' => $lessonIds],
        ownerId: $ownerId,
        taxId: $taxId,
    );
}

it('меняет только заголовок, не трогая остальное', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        title: new CourseTitle('Новый заголовок'),
    );

    expect($updated->title()->value)->toBe('Новый заголовок')
        ->and($updated->locale()->value)->toBe('ru')
        ->and($updated->nextLessonThreshold()->value)->toBe(0.6)
        ->and($scene->courses->saveCalls)->toBe(1);
});

it('null означает «не менять»', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        title: null,
        description: null,
        locale: null,
        threshold: null,
    );

    expect($updated->title()->value)->toBe($course->title()->value)
        ->and($updated->description()->value)->toBe($course->description()->value)
        ->and($updated->locale()->value)->toBe($course->locale()->value);
});

it('меняет порог допуска', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        threshold: new NextLessonThreshold(0.9),
    );

    expect($updated->nextLessonThreshold()->value)->toBe(0.9);
});

it('разрешает сменить язык у курса без уроков', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        locale: new Locale('en'),
    );

    expect($updated->locale()->value)->toBe('en');
});

it('запрещает сменить язык у курса с уроками', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, [10, 20], ownerId: new UserId(1));

    expect(
        fn () => makeEditHeaderAction($scene)->execute(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
            locale: new Locale('en'),
        ),
    )->toThrow(CourseLocaleChangeDenied::class);
});

it('разрешает сменить таксономию у пустого курса', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        taxId: new TaxonomyId(7),
        changeTaxonomy: true,
    );

    expect($updated->taxId()?->value)->toBe(7);
});

it('запрещает сменить таксономию у курса с уроками', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, [10], ownerId: new UserId(1));

    expect(
        fn () => makeEditHeaderAction($scene)->execute(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
            taxId: new TaxonomyId(7),
            changeTaxonomy: true,
        ),
    )->toThrow(CourseTaxonomyChangeDenied::class);
});

it('не применяет смену таксономии без флага changeTaxonomy', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        taxId: new TaxonomyId(7),
        changeTaxonomy: false, // флаг выключен — taxId игнорируется
    );

    expect($updated->taxId())->toBeNull();
});

it('отказывает без прав на редактирование', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    expect(
        fn () => makeEditHeaderAction($scene)->execute(
            CourseViewerContext::student(new UserId(9)),
            $course->id(),
            title: new CourseTitle('Взлом'),
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->courses->saveCalls)->toBe(0);
});

it('отказывает, если курс не найден', function () {
    $scene = new CourseScene();

    expect(
        fn () => makeEditHeaderAction($scene)->execute(
            CourseViewerContext::admin(new UserId(1)),
            new CourseId(999),
            title: new CourseTitle('X'),
        ),
    )->toThrow(CourseNotFound::class);
});

it('меняет описание и порог разом', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        title: new CourseTitle('Курс 2'),
        description: new CourseDescription('Новое описание'),
        threshold: new NextLessonThreshold(0.75),
    );

    expect($updated->title()->value)->toBe('Курс 2')
        ->and($updated->description()->value)->toBe('Новое описание')
        ->and($updated->nextLessonThreshold()->value)->toBe(0.75);
});
