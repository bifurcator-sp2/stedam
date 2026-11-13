<?php

declare(strict_types=1);

use App\Application\Course\Actions\ListCoursesAction;
use App\Application\Course\Context\CourseViewerContext;
use App\Domain\Course\Entities\Course;
use App\Domain\Course\ValueObjects\CourseDescription;
use App\Domain\Course\ValueObjects\CourseId;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\Locale;
use App\Domain\Course\ValueObjects\NextLessonThreshold;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\UserId;
use Tests\Support\CourseScene;

function makeListAction(CourseScene $scene): ListCoursesAction
{
    return new ListCoursesAction($scene->courses);
}

/**
 * Курс заданного владельца; null — системный.
 */
function ownedCourse(CourseScene $scene, ?UserId $ownerId, string $title, ?int $taxId = null): Course
{
    $id = new CourseId($scene->nextCourseId++);

    return $scene->courses->add(Course::restore(
        id: $id,
        userId: $ownerId,
        title: new CourseTitle($title),
        description: new CourseDescription('Описание'),
        locale: new Locale('ru'),
        nextLessonThreshold: new NextLessonThreshold(0.6),
        taxId: $taxId === null ? null : new TaxonomyId($taxId),
        themes: [],
        updatedAt: null,
    ));
}

/**
 * @param list<Course> $courses
 * @return list<string>
 */
function titles(array $courses): array
{
    return array_map(static fn (Course $c) => $c->title()->value, $courses);
}

it('учитель видит свои курсы и системные', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'Мой');
    ownedCourse($scene, new UserId(2), 'Чужой');
    ownedCourse($scene, null, 'Системный');

    $result = makeListAction($scene)->execute(CourseViewerContext::teacher(new UserId(1)));

    expect(titles($result))->toEqualCanonicalizing(['Мой', 'Системный']);
});

it('админ видит все курсы', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'Мой');
    ownedCourse($scene, new UserId(2), 'Чужой');
    ownedCourse($scene, null, 'Системный');

    $result = makeListAction($scene)->execute(CourseViewerContext::admin(new UserId(9)));

    expect(titles($result))->toEqualCanonicalizing(['Мой', 'Чужой', 'Системный']);
});

it('гость видит только системные', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'Мой');
    ownedCourse($scene, null, 'Системный');

    $result = makeListAction($scene)->execute(CourseViewerContext::guest());

    expect(titles($result))->toBe(['Системный']);
});

it('ученик видит только назначенные курсы', function () {
    $scene = new CourseScene();
    $assigned = ownedCourse($scene, new UserId(1), 'Назначен');
    $notAssigned = ownedCourse($scene, new UserId(1), 'Не назначен');

    $scene->courses->markAssignedTo($assigned->id(), new UserId(5));

    $result = makeListAction($scene)->execute(CourseViewerContext::student(new UserId(5)));

    expect(titles($result))->toBe(['Назначен']);
});

it('ученик без назначений получает пустой список', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'Курс');

    $result = makeListAction($scene)->execute(CourseViewerContext::student(new UserId(5)));

    expect($result)->toBe([]);
});

it('фильтрует по таксономии', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'Ветка 7', taxId: 7);
    ownedCourse($scene, new UserId(1), 'Ветка 8', taxId: 8);
    ownedCourse($scene, new UserId(1), 'Без ветки');

    $result = makeListAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        taxId: new TaxonomyId(7),
    );

    expect(titles($result))->toBe(['Ветка 7']);
});

it('ищет по названию', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'Алгебра 7 класс');
    ownedCourse($scene, new UserId(1), 'Геометрия 7 класс');
    ownedCourse($scene, new UserId(1), 'Физика');

    $result = makeListAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        search: '7 класс',
    );

    expect(titles($result))->toEqualCanonicalizing(['Алгебра 7 класс', 'Геометрия 7 класс']);
});

it('поиск нечувствителен к регистру', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'АЛГЕБРА');

    $result = makeListAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        search: 'алгебра',
    );

    expect(titles($result))->toBe(['АЛГЕБРА']);
});

it('фильтры по таксономии и поиску работают вместе', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'Алгебра', taxId: 7);
    ownedCourse($scene, new UserId(1), 'Геометрия', taxId: 7);
    ownedCourse($scene, new UserId(1), 'Алгебра', taxId: 8);

    $result = makeListAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        taxId: new TaxonomyId(7),
        search: 'Алгебра',
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->taxId()?->value)->toBe(7);
});
