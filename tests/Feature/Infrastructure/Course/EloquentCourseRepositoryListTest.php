<?php

declare(strict_types=1);

use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Domain\Course\ValueObjects\TaxonomyId;
use App\Domain\Course\ValueObjects\UserId;
use App\Models\Course as CourseModel;
use App\Models\Taxonomy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function repo(): CourseRepositoryInterface
{
    return app(CourseRepositoryInterface::class);
}

/**
 * Список курсов из страницы (порт отдаёт simple-пагинатор).
 *
 * @return list<\App\Domain\Course\Entities\Course>
 */
function repoItems(\Illuminate\Contracts\Pagination\Paginator $page): array
{
    return array_values($page->items());
}

/**
 * Названия курсов страницы.
 *
 * @return list<string>
 */
function repoTitles(\Illuminate\Contracts\Pagination\Paginator $page): array
{
    return array_map(static fn ($c) => $c->title()->value, repoItems($page));
}

function repoUser(string $name): User
{
    return User::query()->create([
        'name' => $name,
        'email' => $name . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
}

function repoCourse(string $title, ?int $userId): CourseModel
{
    return CourseModel::query()->create([
        'title' => $title,
        'description' => 'Описание',
        'locale' => 'ru',
        'next_lesson_threshold' => 0.6,
        'user_id' => $userId,
    ]);
}

function repoTaxonomy(string $title, int $parentId): Taxonomy
{
    return Taxonomy::query()->create(['parent_id' => $parentId]);
}

function repoCourseIn(string $title, ?int $taxId): CourseModel
{
    return CourseModel::query()->create([
        'title' => $title,
        'description' => 'Описание',
        'locale' => 'ru',
        'next_lesson_threshold' => 0.6,
        'user_id' => null,
        'tax_id' => $taxId,
    ]);
}

function repoCourseWithDescription(string $title, string $description, ?int $taxId): CourseModel
{
    return CourseModel::query()->create([
        'title' => $title,
        'description' => $description,
        'locale' => 'ru',
        'next_lesson_threshold' => 0.6,
        'user_id' => null,
        'tax_id' => $taxId,
    ]);
}

it('ownerId = null, includeSystem = false — отдаёт пусто, а не всё', function () {
    $owner = repoUser('владелец');
    repoCourse('Чужой', $owner->id);

    $result = repo()->listForOwner(
        ownerId: null,
        taxId: null,
        search: null,
        includeSystem: false,
    );

    expect(repoItems($result))->toBe([]);
});

it('ownerId = null, includeSystem = true — отдаёт только системные', function () {
    $owner = repoUser('владелец');
    repoCourse('Чужой', $owner->id);
    repoCourse('Системный', null);

    $result = repo()->listForOwner(
        ownerId: null,
        taxId: null,
        search: null,
        includeSystem: true,
    );

    expect(repoItems($result))->toHaveCount(1)
        ->and(repoItems($result)[0]->title()->value)->toBe('Системный');
});

it('ownerId задан, includeSystem = false — только свои', function () {
    $me = repoUser('я');
    repoCourse('Мой', $me->id);
    repoCourse('Системный', null);

    $result = repo()->listForOwner(new UserId($me->id), null, null, false);

    expect(repoItems($result))->toHaveCount(1)
        ->and(repoItems($result)[0]->title()->value)->toBe('Мой');
});

it('ownerId задан, includeSystem = true — свои + системные', function () {
    $me = repoUser('я');
    $other = repoUser('другой');
    repoCourse('Мой', $me->id);
    repoCourse('Системный', null);
    repoCourse('Чужой', $other->id);

    $titles = repoTitles(repo()->listForOwner(new UserId($me->id), null, null, true));

    expect($titles)->toEqualCanonicalizing(['Мой', 'Системный']);
});

it('tax_id фильтрует курсы вместе с поддеревом', function () {
    $root = repoTaxonomy('Математика', -1);
    $child = repoTaxonomy('Алгебра', $root->id);
    $grandchild = repoTaxonomy('Линейные уравнения', $child->id);
    $foreign = repoTaxonomy('Биология', -1);

    repoCourseIn('Алгебра', $child->id);
    repoCourseIn('Уравнения', $grandchild->id);
    repoCourseIn('Биология', $foreign->id);
    repoCourseIn('Без раздела', null);

    $titles = repoTitles(...);

    expect($titles(repo()->listForOwner(null, new TaxonomyId($root->id), null, true, allOwners: true)))
        ->toEqualCanonicalizing(['Алгебра', 'Уравнения'])
        ->and($titles(repo()->listForOwner(null, new TaxonomyId($child->id), null, true, allOwners: true)))
        ->toEqualCanonicalizing(['Алгебра', 'Уравнения'])
        ->and($titles(repo()->listForOwner(null, new TaxonomyId($grandchild->id), null, true, allOwners: true)))
        ->toEqualCanonicalizing(['Уравнения']);
});

it('search ищет по названию и описанию без учёта регистра', function () {
    repoCourse('Основы Python', null);
    repoCourseWithDescription('Курс для 8 класса', 'Готовимся к олимпиаде по PYTHON', null);
    repoCourse('Алгоритмы', null);

    $titles = repoTitles(...);

    expect($titles(repo()->listForOwner(null, null, 'python', true, allOwners: true)))
        ->toEqualCanonicalizing(['Основы Python', 'Курс для 8 класса'])
        ->and($titles(repo()->listForOwner(null, null, 'олимпиаде', true, allOwners: true)))
        ->toEqualCanonicalizing(['Курс для 8 класса'])
        ->and($titles(repo()->listForOwner(null, null, 'kotlin', true, allOwners: true)))
        ->toBe([]);
});

it('listForStudent учитывает фильтры и отдаёт только назначенные', function () {
    $student = repoUser('ученик');
    $assigned = repoCourseIn('Назначенный', null);
    repoCourseIn('Неназначенный', null);

    DB::table('course_assignments')->insert([
        'course_id' => $assigned->id,
        'student_id' => $student->id,
        'assigned_by' => $student->id,
        'completion' => 0,
        'grade' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $titles = repoTitles(...);

    expect($titles(repo()->listForStudent(new UserId($student->id))))
        ->toBe(['Назначенный'])
        ->and($titles(repo()->listForStudent(new UserId($student->id), null, 'назнач')))
        ->toBe(['Назначенный'])
        ->and($titles(repo()->listForStudent(new UserId($student->id), null, 'неназн')))
        ->toBe([]);
});

it('allOwners = true — отдаёт всё, включая чужие', function () {
    $me = repoUser('я');
    $other = repoUser('другой');
    repoCourse('Мой', $me->id);
    repoCourse('Системный', null);
    repoCourse('Чужой', $other->id);

    $titles = repoTitles(repo()->listForOwner(null, null, null, true, allOwners: true));

    expect($titles)->toEqualCanonicalizing(['Мой', 'Системный', 'Чужой']);
});
