<?php

declare(strict_types=1);

use App\Application\Course\Actions\EditCourseHeaderAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Application\Course\Exceptions\CourseNotFound;
use App\Application\Course\Services\LessonTimeGapResolver;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\Entities\CourseLesson;
use App\Domain\Course\Entities\CourseTheme;
use App\Domain\Course\Exceptions\CourseIsPublishedEdit;
use App\Domain\Course\Exceptions\CourseLocaleChangeDenied;
use App\Domain\Course\Exceptions\CourseTaxonomyChangeDenied;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\NextLessonThreshold;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\ThresholdMultiplier;
use App\Domain\Course\ValueObjects\TimeGap;
use App\Domain\Course\ValueObjects\TimeGapMultiplier;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\CourseScene;

function makeEditHeaderAction(CourseScene $scene): EditCourseHeaderAction
{
    return new EditCourseHeaderAction(
        $scene->courses,
        $scene->lessons,
        new LessonTimeGapResolver($scene->lessons),
        $scene->events,
    );
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
            thresholdMultiplier: ThresholdMultiplier::default(),
            timeGapMultiplier: TimeGapMultiplier::default(),
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

/**
 * Опубликованный курс с одним уроком в теме.
 */
function publishedCourse(CourseScene $scene, UserId $ownerId): Course
{
    return $scene->savedCourseWithLessons(['Тема 1' => [10]], $ownerId, null, published: true);
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
    );

    expect($updated->title()->value)->toBe($course->title()->value)
        ->and($updated->description()->value)->toBe($course->description()->value)
        ->and($updated->locale()->value)->toBe($course->locale()->value);
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

it('меняет название и описание разом', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        title: new CourseTitle('Курс 2'),
        description: new CourseDescription('Новое описание'),
    );

    expect($updated->title()->value)->toBe('Курс 2')
        ->and($updated->description()->value)->toBe('Новое описание');
});

// ---------------------------------------------------------------------
// Публикация
// ---------------------------------------------------------------------

it('новый курс создаётся неопубликованным', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    expect($course->isPublished())->toBeFalse();
});

it('публикует курс', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        published: true,
    );

    expect($updated->isPublished())->toBeTrue();
});

it('снимает публикацию у опубликованного курса', function () {
    $scene = new CourseScene();
    $course = publishedCourse($scene, new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        published: false,
    );

    expect($updated->isPublished())->toBeFalse();
});

it('снятие публикации атомарно: правки в том же запросе игнорируются', function () {
    $scene = new CourseScene();
    $course = publishedCourse($scene, new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        title: new CourseTitle('Новый заголовок'),
        description: new CourseDescription('Новое описание'),
        published: false,
    );

    expect($updated->isPublished())->toBeFalse()
        ->and($updated->title()->value)->toBe($course->title()->value)
        ->and($updated->description()->value)->toBe($course->description()->value);
});

it('после снятия публикации курс снова редактируется', function () {
    $scene = new CourseScene();
    $course = publishedCourse($scene, new UserId(1));
    $action = makeEditHeaderAction($scene);

    $unpublished = $action->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        published: false,
    );

    $updated = $action->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $unpublished->id(),
        title: new CourseTitle('Новый заголовок'),
    );

    expect($updated->title()->value)->toBe('Новый заголовок');
});

it('публикует курс с одновременной правкой шапки', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        title: new CourseTitle('Новый заголовок'),
        description: new CourseDescription('Новое описание'),
        thresholdMultiplier: new ThresholdMultiplier(1.5),
        published: true,
    );

    expect($updated->isPublished())->toBeTrue()
        ->and($updated->title()->value)->toBe('Новый заголовок')
        ->and($updated->description()->value)->toBe('Новое описание')
        ->and($updated->thresholdMultiplier()->value)->toBe(1.5)
        ->and($scene->courses->saveCalls)->toBe(1);
});

it('опубликованный курс нельзя изменить, не сняв публикацию', function () {
    $scene = new CourseScene();
    $course = publishedCourse($scene, new UserId(1));

    expect(
        fn () => makeEditHeaderAction($scene)->execute(
            CourseViewerContext::teacher(new UserId(1)),
            $course->id(),
            title: new CourseTitle('Взлом'),
        ),
    )->toThrow(CourseIsPublishedEdit::class);

    expect($scene->courses->findById($course->id())?->title()->value)
        ->toBe($course->title()->value);
});

it('повторная публикация опубликованного курса допустима', function () {
    $scene = new CourseScene();
    $course = publishedCourse($scene, new UserId(1));

    $updated = makeEditHeaderAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        $course->id(),
        published: true,
    );

    expect($updated->isPublished())->toBeTrue();
});

it('публиковать курс может только владелец', function () {
    $scene = new CourseScene();
    $course = savedCourse($scene, ownerId: new UserId(1));

    expect(
        fn () => makeEditHeaderAction($scene)->execute(
            CourseViewerContext::student(new UserId(9)),
            $course->id(),
            published: true,
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->courses->findById($course->id())?->isPublished())->toBeFalse();
});
