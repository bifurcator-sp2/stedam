<?php

declare(strict_types=1);

use App\Application\Course\Ports\CourseRepositoryInterface;
use App\Domain\Course\ValueObjects\UserId;
use App\Models\Course as CourseModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function repo(): CourseRepositoryInterface
{
    return app(CourseRepositoryInterface::class);
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

it('ownerId = null, includeSystem = false — отдаёт пусто, а не всё', function () {
    $owner = repoUser('владелец');
    repoCourse('Чужой', $owner->id);

    $result = repo()->listForOwner(
        ownerId: null,
        taxId: null,
        search: null,
        includeSystem: false,
    );

    expect($result)->toBe([]);
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

    expect($result)->toHaveCount(1)
        ->and($result[0]->title()->value)->toBe('Системный');
});

it('ownerId задан, includeSystem = false — только свои', function () {
    $me = repoUser('я');
    repoCourse('Мой', $me->id);
    repoCourse('Системный', null);

    $result = repo()->listForOwner(new UserId($me->id), null, null, false);

    expect($result)->toHaveCount(1)
        ->and($result[0]->title()->value)->toBe('Мой');
});

it('ownerId задан, includeSystem = true — свои + системные', function () {
    $me = repoUser('я');
    $other = repoUser('другой');
    repoCourse('Мой', $me->id);
    repoCourse('Системный', null);
    repoCourse('Чужой', $other->id);

    $result = repo()->listForOwner(new UserId($me->id), null, null, true);

    $titles = array_map(static fn ($c) => $c->title()->value, $result);

    expect($titles)->toEqualCanonicalizing(['Мой', 'Системный']);
});

it('allOwners = true — отдаёт всё, включая чужие', function () {
    $me = repoUser('я');
    $other = repoUser('другой');
    repoCourse('Мой', $me->id);
    repoCourse('Системный', null);
    repoCourse('Чужой', $other->id);

    $result = repo()->listForOwner(null, null, null, true, allOwners: true);

    $titles = array_map(static fn ($c) => $c->title()->value, $result);

    expect($titles)->toEqualCanonicalizing(['Мой', 'Системный', 'Чужой']);
});
