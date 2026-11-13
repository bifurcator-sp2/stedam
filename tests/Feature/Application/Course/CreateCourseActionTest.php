<?php

declare(strict_types=1);

use App\Application\Course\Actions\CreateCourseAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Application\Course\Exceptions\CourseAccessDenied;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\NextLessonThreshold;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\CourseScene;

function makeCreateAction(CourseScene $scene): CreateCourseAction
{
    return new CreateCourseAction($scene->courses);
}

function createCourse(
    CreateCourseAction $action,
    CourseViewerContext $viewer,
    ?UserId $ownerId,
    ?TaxonomyId $taxId = null,
) {
    return $action->execute(
        viewer: $viewer,
        ownerId: $ownerId,
        title: new CourseTitle('Новый курс'),
        description: new CourseDescription('Описание нового курса'),
        locale: new Locale('ru'),
        threshold: new NextLessonThreshold(0.6),
        taxId: $taxId,
    );
}

it('создаёт курс с шапкой и без состава', function () {
    $scene = new CourseScene();

    $course = createCourse(
        makeCreateAction($scene),
        CourseViewerContext::teacher(new UserId(1)),
        new UserId(1),
        new TaxonomyId(7),
    );

    expect($course->id())->not->toBeNull()
        ->and($course->title()->value)->toBe('Новый курс')
        ->and($course->userId()->value)->toBe(1)
        ->and($course->taxId()?->value)->toBe(7)
        ->and($course->themes())->toBe([])
        ->and($scene->courses->saveCalls)->toBe(1);
});

it('сохраняет курс в репозитории', function () {
    $scene = new CourseScene();

    $course = createCourse(
        makeCreateAction($scene),
        CourseViewerContext::teacher(new UserId(1)),
        new UserId(1),
    );

    expect($scene->courses->findById($course->id()))->not->toBeNull();
});

it('учитель НЕ может создать системный курс', function () {
    $scene = new CourseScene();

    // Системный курс (ownerId = null) создаёт только admin (§13.1).
    expect(
        fn () => createCourse(
            makeCreateAction($scene),
            CourseViewerContext::teacher(new UserId(1)),
            null,
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->courses->saveCalls)->toBe(0);
});

it('methodologist НЕ может создать системный курс', function () {
    $scene = new CourseScene();

    // Методист видит и правит системные курсы, но НЕ создаёт их.
    expect(
        fn () => createCourse(
            makeCreateAction($scene),
            CourseViewerContext::methodologist(new UserId(3)),
            null,
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->courses->saveCalls)->toBe(0);
});

it('admin создаёт системный курс', function () {
    $scene = new CourseScene();

    $course = createCourse(
        makeCreateAction($scene),
        CourseViewerContext::admin(new UserId(3)),
        null,
    );

    expect($course->userId())->toBeNull();
});

it('ученик не может создать курс', function () {
    $scene = new CourseScene();

    expect(
        fn () => createCourse(
            makeCreateAction($scene),
            CourseViewerContext::student(new UserId(9)),
            new UserId(9),
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->courses->saveCalls)->toBe(0);
});

it('гость не может создать курс', function () {
    $scene = new CourseScene();

    expect(
        fn () => createCourse(
            makeCreateAction($scene),
            CourseViewerContext::guest(),
            null,
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->courses->saveCalls)->toBe(0);
});

it('учитель не может создать курс другому учителю', function () {
    $scene = new CourseScene();

    expect(
        fn () => createCourse(
            makeCreateAction($scene),
            CourseViewerContext::teacher(new UserId(1)),
            new UserId(42), // чужой
        ),
    )->toThrow(CourseAccessDenied::class);
});

it('админ НЕ создаёт курс чужому владельцу', function () {
    $scene = new CourseScene();

    // «Админ» без роли teacher не создаёт авторские курсы вообще;
    // его исключительное право — системные курсы (ownerId = null).
    expect(
        fn () => createCourse(
            makeCreateAction($scene),
            CourseViewerContext::admin(new UserId(1)),
            new UserId(42),
        ),
    )->toThrow(CourseAccessDenied::class);

    expect($scene->courses->saveCalls)->toBe(0);
});

it('созданный курс имеет нулевую пройденность', function () {
    $scene = new CourseScene();

    $course = createCourse(
        makeCreateAction($scene),
        CourseViewerContext::teacher(new UserId(1)),
        new UserId(1),
    );

    expect($course->countLessons())->toBe(0);
});

// === Матрица прав §13.1: редактирование по владению ===

it('админ редактирует системный курс', function () {
    $context = CourseViewerContext::admin(new UserId(1));

    expect($context->canEdit(null))->toBeTrue();
});

it('methodologist НЕ редактирует системный курс', function () {
    // У системного курса нет автора → правило владения не работает,
    // единственное исключение — admin.
    $context = CourseViewerContext::methodologist(new UserId(3));

    expect($context->canEdit(null))->toBeFalse();
});

it('headteacher не редактирует системный курс', function () {
    $context = CourseViewerContext::headteacher(new UserId(4));

    expect($context->canEdit(null))->toBeFalse();
});

it('methodologist не редактирует чужой авторский курс, хотя видит его', function () {
    $context = CourseViewerContext::methodologist(new UserId(3));

    // seesEverything() даёт видимость, но не право правки.
    expect($context->seesEverything())->toBeTrue()
        ->and($context->canEdit(new UserId(42)))->toBeFalse();
});

it('headteacher не редактирует чужой курс, хотя видит его', function () {
    $context = CourseViewerContext::headteacher(new UserId(4));

    expect($context->canEdit(new UserId(42)))->toBeFalse()
        ->and($context->canEdit(new UserId(4)))->toBeTrue();
});

it('админ не редактирует чужой авторский курс', function () {
    $context = CourseViewerContext::admin(new UserId(1));

    // Админ правит только системные и СВОИ (если у него есть роль teacher).
    expect($context->canEdit(new UserId(1)))->toBeTrue()
        ->and($context->canEdit(new UserId(42)))->toBeFalse();
});

it('учитель редактирует только свой курс', function () {
    $context = CourseViewerContext::teacher(new UserId(1));

    expect($context->canEdit(new UserId(1)))->toBeTrue()
        ->and($context->canEdit(new UserId(42)))->toBeFalse();
});

it('учитель не редактирует системный курс', function () {
    $context = CourseViewerContext::teacher(new UserId(1));

    expect($context->canEdit(null))->toBeFalse();
});

it('ученик и гость не редактируют ничего', function () {
    expect(CourseViewerContext::student(new UserId(9))->canEdit(new UserId(9)))->toBeFalse()
        ->and(CourseViewerContext::student(new UserId(9))->canEdit(null))->toBeFalse()
        ->and(CourseViewerContext::guest()->canEdit(null))->toBeFalse();
});
