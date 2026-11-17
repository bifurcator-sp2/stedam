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
use App\Domain\Course\ValueObjects\ThresholdMultiplier;
use App\Domain\Course\ValueObjects\TimeGapMultiplier;
use App\Domain\Course\ValueObjects\UserId;
use Illuminate\Contracts\Pagination\Paginator;
use Tests\Support\CourseScene;

function makeListAction(CourseScene $scene): ListCoursesAction
{
    return new ListCoursesAction($scene->courses);
}

/**
 * Курсы страницы в виде доменных объектов.
 *
 * @return list<Course>
 */
function pageItems(Paginator $result): array
{
    return $result->items();
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
        thresholdMultiplier: ThresholdMultiplier::default(),
        timeGapMultiplier: TimeGapMultiplier::default(),
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

    expect(titles(pageItems($result)))->toEqualCanonicalizing(['Мой', 'Системный']);
});

it('админ видит все курсы', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'Мой');
    ownedCourse($scene, new UserId(2), 'Чужой');
    ownedCourse($scene, null, 'Системный');

    $result = makeListAction($scene)->execute(CourseViewerContext::admin(new UserId(9)));

    expect(titles(pageItems($result)))->toEqualCanonicalizing(['Мой', 'Чужой', 'Системный']);
});

it('гость видит только системные', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'Мой');
    ownedCourse($scene, null, 'Системный');

    $result = makeListAction($scene)->execute(CourseViewerContext::guest());

    expect(titles(pageItems($result)))->toBe(['Системный']);
});

it('ученик видит только назначенные курсы', function () {
    $scene = new CourseScene();
    $assigned = ownedCourse($scene, new UserId(1), 'Назначен');
    $notAssigned = ownedCourse($scene, new UserId(1), 'Не назначен');

    $scene->courses->markAssignedTo($assigned->id(), new UserId(5));

    $result = makeListAction($scene)->execute(CourseViewerContext::student(new UserId(5)));

    expect(titles(pageItems($result)))->toBe(['Назначен']);
});

it('ученик без назначений получает пустой список', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'Курс');

    $result = makeListAction($scene)->execute(CourseViewerContext::student(new UserId(5)));

    expect(pageItems($result))->toBe([])
        ->and($result->hasMorePages())->toBeFalse();
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

    expect(titles(pageItems($result)))->toBe(['Ветка 7']);
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

    expect(titles(pageItems($result)))->toEqualCanonicalizing(['Алгебра 7 класс', 'Геометрия 7 класс']);
});

it('поиск нечувствителен к регистру', function () {
    $scene = new CourseScene();
    ownedCourse($scene, new UserId(1), 'АЛГЕБРА');

    $result = makeListAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        search: 'алгебра',
    );

    expect(titles(pageItems($result)))->toBe(['АЛГЕБРА']);
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

    expect(pageItems($result))->toHaveCount(1)
        ->and(pageItems($result)[0]->taxId()?->value)->toBe(7);
});

it('листает по страницам и сообщает has_more_pages', function () {
    $scene = new CourseScene();

    foreach (['Первый', 'Второй', 'Третий'] as $title) {
        ownedCourse($scene, new UserId(1), $title);
    }

    $first = makeListAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        page: 1,
        perPage: 2,
    );

    expect(pageItems($first))->toHaveCount(2)
        ->and($first->hasMorePages())->toBeTrue();

    $second = makeListAction($scene)->execute(
        CourseViewerContext::teacher(new UserId(1)),
        page: 2,
        perPage: 2,
    );

    expect(pageItems($second))->toHaveCount(1)
        ->and($second->hasMorePages())->toBeFalse();
});

it('родитель получает объединённую страницу детей без дублей', function () {
    $scene = new CourseScene();

    $shared = ownedCourse($scene, new UserId(1), 'Общий');
    ownedCourse($scene, new UserId(1), 'Только у первого');

    $scene->courses->markAssignedTo($shared->id(), new UserId(5));
    $scene->courses->markAssignedTo($shared->id(), new UserId(6));

    $result = makeListAction($scene)->execute(
        CourseViewerContext::parent(new UserId(2), [new UserId(5), new UserId(6)]),
    );

    expect(titles(pageItems($result)))->toBe(['Общий']);
});
