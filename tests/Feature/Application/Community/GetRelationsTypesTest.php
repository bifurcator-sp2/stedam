<?php

declare(strict_types=1);

use App\Application\Community\Actions\GetRelationsTypesAction;
use App\Application\Community\DTOs\RelationTypeSummary;
use App\Application\Community\Ports\RelationsQueryInterface;
use App\Domain\Community\ValueObjects\UserId;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Хелпер: ребро графа владения/наблюдения.
 */
function communityRelate(
    User $parent,
    User $child,
    string $relation,
    ?DateTimeImmutable $activeFrom = null,
    ?DateTimeImmutable $activeTo = null,
): void {
    DB::table('user_relations')->insert([
        'parent_user_id' => $parent->id,
        'child_user_id' => $child->id,
        'relation' => $relation,
        'active_from' => $activeFrom?->format('Y-m-d H:i:sP'),
        'active_to' => $activeTo?->format('Y-m-d H:i:sP'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function communityAction(): GetRelationsTypesAction
{
    return new GetRelationsTypesAction(app(RelationsQueryInterface::class));
}

/**
 * Индекс результата по паре «тип:направление».
 *
 * @param list<RelationTypeSummary> $summaries
 * @return array<string, RelationTypeSummary>
 */
function communityIndex(array $summaries): array
{
    $indexed = [];
    foreach ($summaries as $summary) {
        $indexed[$summary->relation.':'.$summary->direction] = $summary;
    }

    return $indexed;
}

it('returns both sides of every relation type with zero counts', function () {
    $user = User::factory()->create();

    $indexed = communityIndex(communityAction()->execute(new UserId($user->id)));

    expect(array_keys($indexed))->toBe([
        'teacher:outgoing', 'teacher:incoming',
        'parent:outgoing', 'parent:incoming',
        'methodologist:outgoing', 'methodologist:incoming',
        'headteacher:outgoing', 'headteacher:incoming',
    ])
        ->and($indexed['parent:outgoing']->role)->toBe('parent')
        ->and($indexed['parent:outgoing']->label)->toBe('Дети')
        ->and($indexed['parent:incoming']->role)->toBe('student')
        ->and($indexed['parent:incoming']->label)->toBe('Родители')
        ->and($indexed['parent:outgoing']->description)->toBe(
            'Пользователей, зарегистрированных как мои <b>Дети</b> на этом проекте, пока нет.'
            .'<br>В этой связи я являюсь <b>Родителем</b>, а они являются моими <b>Детьми</b>'
        )
        ->and($indexed['parent:incoming']->description)->toBe(
            'Пользователей, зарегистрированных как мои <b>Родители</b> на этом проекте, пока нет.'
            .'<br>В этой связи я являюсь <b>Ребёнком</b>, а они являются моими <b>Родителями</b>'
        )
        ->and($indexed['parent:outgoing']->count)->toBe(0)
        ->and($indexed['parent:outgoing']->available)->toBeFalse();
});

it('counts the same link from both sides of the user', function () {
    $parent = User::factory()->create();
    $child1 = User::factory()->create();
    $child2 = User::factory()->create();
    $grandparent = User::factory()->create();

    // Пользователь — родитель для двух детей.
    communityRelate($parent, $child1, 'parent');
    communityRelate($parent, $child2, 'parent');
    // Пользователь — ребёнок своего родителя.
    communityRelate($grandparent, $parent, 'parent');

    $indexed = communityIndex(communityAction()->execute(new UserId($parent->id)));

    expect($indexed['parent:outgoing']->count)->toBe(2)
        ->and($indexed['parent:outgoing']->counterpartRole)->toBe('student')
        ->and($indexed['parent:incoming']->count)->toBe(1)
        ->and($indexed['parent:incoming']->counterpartRole)->toBe('parent')
        ->and($indexed['parent:incoming']->available)->toBeTrue();
});

it('ignores ended and not-started links on both sides', function () {
    $teacher = User::factory()->create();
    $student = User::factory()->create();
    $otherTeacher = User::factory()->create();

    communityRelate(
        $teacher,
        $student,
        'teacher',
        null,
        new DateTimeImmutable('-1 day'),
    );
    communityRelate(
        $teacher,
        $otherTeacher,
        'teacher',
        new DateTimeImmutable('+1 day'),
    );

    $indexed = communityIndex(communityAction()->execute(new UserId($student->id)));
    expect($indexed['teacher:incoming']->count)->toBe(0);

    $indexed = communityIndex(communityAction()->execute(new UserId($teacher->id)));
    expect($indexed['teacher:outgoing']->count)->toBe(0);
});

it('exposes my-relations endpoint with both participation sides', function () {
    $parent = User::factory()->create();
    $child = User::factory()->create();
    $grandparent = User::factory()->create();

    communityRelate($parent, $child, 'parent');
    communityRelate($grandparent, $parent, 'parent');

    $this->actingAs($parent)
        ->getJson('/api/my-relations')
        ->assertOk()
        ->assertJsonPath('data.2.relation', 'parent')
        ->assertJsonPath('data.2.direction', 'outgoing')
        ->assertJsonPath('data.2.role', 'parent')
        ->assertJsonPath('data.2.label', 'Дети')
        ->assertJsonPath('data.2.description', 'Пользователь, зарегистрированный как мой <b>Дети</b> на этом проекте.'
            .'<br>Я являюсь по отношению к нему <b>Родителем</b>: он является моим <b>Ребёнком</b>')
        ->assertJsonPath('data.2.count', 1)
        ->assertJsonPath('data.3.relation', 'parent')
        ->assertJsonPath('data.3.direction', 'incoming')
        ->assertJsonPath('data.3.role', 'student')
        ->assertJsonPath('data.3.label', 'Родители')
        ->assertJsonPath('data.3.count', 1);
});

it('labels teacher links from the viewer side', function () {
    $teacher = User::factory()->create();
    $student = User::factory()->create();
    communityRelate($teacher, $student, 'teacher');

    $this->actingAs($teacher)
        ->getJson('/api/my-relations')
        ->assertOk()
        ->assertJsonPath('data.0.direction', 'outgoing')
        ->assertJsonPath('data.0.role', 'teacher')
        ->assertJsonPath('data.0.label', 'Ученики')
        ->assertJsonPath('data.0.count', 1);

    $this->actingAs($student)
        ->getJson('/api/my-relations')
        ->assertOk()
        ->assertJsonPath('data.1.direction', 'incoming')
        ->assertJsonPath('data.1.role', 'student')
        ->assertJsonPath('data.1.label', 'Учителя')
        ->assertJsonPath('data.1.count', 1);
});

it('describes staff members as employees of their manager', function () {
    $user = User::factory()->create();

    $indexed = communityIndex(communityAction()->execute(new UserId($user->id)));

    // Методист (parent) — руководитель учителя (child).
    expect($indexed['methodologist:outgoing']->label)->toBe('Сотрудники - Учителя')
        ->and($indexed['methodologist:outgoing']->description)->toBe(
            'Пользователей, зарегистрированных как мои <b>Сотрудники - Учителя</b> на этом проекте, пока нет.'
            .'<br>В этой связи я являюсь <b>Руководителем</b>, а они являются моими <b>Сотрудниками - Учителями</b>'
        )
        ->and($indexed['methodologist:incoming']->label)->toBe('Руководители - Методисты')
        ->and($indexed['methodologist:incoming']->role)->toBe('teacher')
        ->and($indexed['methodologist:incoming']->description)->toBe(
            'Пользователей, зарегистрированных как мои <b>Руководители - Методисты</b> на этом проекте, пока нет.'
            .'<br>В этой связи я являюсь <b>Учителем</b>, а они являются моими <b>Руководителями</b>'
        );

    // Завуч (parent) — руководитель методиста (child).
    expect($indexed['headteacher:outgoing']->label)->toBe('Сотрудники - Методисты')
        ->and($indexed['headteacher:outgoing']->description)->toBe(
            'Пользователей, зарегистрированных как мои <b>Сотрудники - Методисты</b> на этом проекте, пока нет.'
            .'<br>В этой связи я являюсь <b>Руководителем</b>, а они являются моими <b>Сотрудниками - Методистами</b>'
        )
        ->and($indexed['headteacher:incoming']->label)->toBe('Руководители - Завучи')
        ->and($indexed['headteacher:incoming']->description)->toBe(
            'Пользователей, зарегистрированных как мои <b>Руководители - Завучи</b> на этом проекте, пока нет.'
            .'<br>В этой связи я являюсь <b>Методистом</b>, а они являются моими <b>Руководителями</b>'
        );
});

it('pluralizes the description by the number of links', function () {
    $parent = User::factory()->create();
    $child1 = User::factory()->create();
    $child2 = User::factory()->create();

    $indexed = communityIndex(communityAction()->execute(new UserId($parent->id)));
    expect($indexed['parent:outgoing']->description)->toStartWith(
        'Пользователей, зарегистрированных как мои <b>Дети</b> на этом проекте, пока нет.',
    );

    communityRelate($parent, $child1, 'parent');
    $indexed = communityIndex(communityAction()->execute(new UserId($parent->id)));
    expect($indexed['parent:outgoing']->description)->toStartWith(
        'Пользователь, зарегистрированный как мой <b>Дети</b> на этом проекте.',
    )->and($indexed['parent:outgoing']->description)->toContain(
        'Я являюсь по отношению к нему <b>Родителем</b>: он является моим <b>Ребёнком</b>',
    );

    communityRelate($parent, $child2, 'parent');
    $indexed = communityIndex(communityAction()->execute(new UserId($parent->id)));
    expect($indexed['parent:outgoing']->description)->toBe(
        'Пользователи, зарегистрированные как мои Дети на этом проекте.'
        .'<br>Я являюсь по отношению к ним <b>Родителем</b>: они являются моими <b>Ребёнком</b>',
    )->and($indexed['parent:outgoing']->description)->not->toContain('{1}', '[2,*]');
});

it('takes relation texts from translations', function () {
    app()->setLocale('en');

    $user = User::factory()->create();

    $indexed = communityIndex(communityAction()->execute(new UserId($user->id)));

    expect($indexed['parent:outgoing']->label)->toBe('Children')
        ->and($indexed['parent:incoming']->label)->toBe('Parents')
        ->and($indexed['methodologist:outgoing']->label)->toBe('Employees - Teachers')
        ->and($indexed['headteacher:incoming']->label)->toBe('Heads - Headteachers')
        ->and($indexed['parent:outgoing']->description)->toBe(
            'There are no users registered as my <b>Children</b> on this project yet.'
            .'<br>In this relation I am the <b>Parent</b>, and they are my <b>Children</b>'
        );
});
