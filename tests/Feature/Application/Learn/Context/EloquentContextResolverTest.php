<?php

declare(strict_types=1);

use App\Application\Learn\Context\RelationType;
use App\Application\Learn\Context\ViewerRole;
use App\Domain\Learn\ValueObjects\UserId;
use App\Infrastructure\Persistence\Context\EloquentContextResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Хелпер: гарантирует, что Spatie-роль существует, и назначает её пользователю.
 */
function userWithRole(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);

    /** @var User $user */
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Хелпер: ребро графа владения.
 */
function relate(User $parent, User $child, RelationType $relation, ?DateTimeImmutable $activeTo = null): void
{
    DB::table('user_relations')->insert([
        'parent_user_id' => $parent->id,
        'child_user_id'  => $child->id,
        'relation'       => $relation->value,
        'active_from'    => null,
        'active_to'      => $activeTo?->format('Y-m-d H:i:sP'),
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
}

function resolver(): EloquentContextResolver
{
    return new EloquentContextResolver();
}

it('student sees only self', function () {
    $student = userWithRole('student');

    $ctx = resolver()->resolve(new UserId($student->id));

    expect($ctx->visibleUserIds())->toBe([$student->id])
        ->and($ctx->roles)->toBe([ViewerRole::Student])
        ->and($ctx->canStart)->toBeTrue()
        ->and($ctx->canSave)->toBeTrue()
        ->and($ctx->canGrade)->toBeFalse()
        ->and($ctx->canAssign)->toBeFalse();
});

it('teacher sees own students and self', function () {
    $teacher = userWithRole('teacher');
    $s1 = userWithRole('student');
    $s2 = userWithRole('student');
    $stranger = userWithRole('student');

    relate($teacher, $s1, RelationType::Teacher);
    relate($teacher, $s2, RelationType::Teacher);

    $ctx = resolver()->resolve(new UserId($teacher->id));

    $expected = [$teacher->id, $s1->id, $s2->id];
    sort($expected);

    expect($ctx->visibleUserIds())->toBe($expected)
        ->and($ctx->canSeeUser($s1->id))->toBeTrue()
        ->and($ctx->canSeeUser($stranger->id))->toBeFalse()
        ->and($ctx->canGrade)->toBeTrue()
        ->and($ctx->canAssign)->toBeTrue()
        ->and($ctx->canStart)->toBeFalse();
});

it('parent sees children, read-only', function () {
    $parent = userWithRole('parent');
    $kid1 = userWithRole('student');
    $kid2 = userWithRole('student');

    relate($parent, $kid1, RelationType::Parent);
    relate($parent, $kid2, RelationType::Parent);

    $ctx = resolver()->resolve(new UserId($parent->id));

    $expected = [$parent->id, $kid1->id, $kid2->id];
    sort($expected);

    expect($ctx->visibleUserIds())->toBe($expected)
        ->and($ctx->isReadOnly())->toBeTrue() // родитель — только чтение
        ->and($ctx->canGrade)->toBeFalse()
        ->and($ctx->canAssign)->toBeFalse()
        ->and($ctx->canStart)->toBeFalse()
        ->and($ctx->canSave)->toBeFalse();
});

it('methodologist sees students of own teachers through 2 hops', function () {
    $methodologist = userWithRole('methodologist');
    $teacher = userWithRole('teacher');
    $student = userWithRole('student');

    relate($methodologist, $teacher, RelationType::Methodologist);
    relate($teacher, $student, RelationType::Teacher);

    $ctx = resolver()->resolve(new UserId($methodologist->id));

    expect($ctx->visibleUserIds())->toBe([$methodologist->id, $student->id])
        ->and($ctx->canSeeUser($teacher->id))->toBeFalse() // промежуточный учитель не виден
        ->and($ctx->isReadOnly())->toBeTrue();
});

it('headteacher sees students through 3 hops, not intermediate levels', function () {
    $headteacher = userWithRole('headteacher');
    $methodologist = userWithRole('methodologist');
    $teacher = userWithRole('teacher');
    $student = userWithRole('student');

    relate($headteacher, $methodologist, RelationType::Headteacher);
    relate($methodologist, $teacher, RelationType::Methodologist);
    relate($teacher, $student, RelationType::Teacher);

    $ctx = resolver()->resolve(new UserId($headteacher->id));

    $expected = [$headteacher->id, $student->id];
    sort($expected);

    expect($ctx->visibleUserIds())->toBe($expected)
        ->and($ctx->canSeeUser($methodologist->id))->toBeFalse()
        ->and($ctx->canSeeUser($teacher->id))->toBeFalse()
        ->and($ctx->isReadOnly())->toBeTrue();
});

it('multi-role unions scopes (teacher + parent)', function () {
    $user = userWithRole('teacher');
    Role::firstOrCreate(['name' => 'parent', 'guard_name' => 'web']);
    $user->assignRole('parent');

    $studentOf = userWithRole('student');
    $ownKid = userWithRole('student');

    relate($user, $studentOf, RelationType::Teacher);
    relate($user, $ownKid, RelationType::Parent);

    $ctx = resolver()->resolve(new UserId($user->id));

    $expected = [$user->id, $studentOf->id, $ownKid->id];
    sort($expected);

    expect($ctx->visibleUserIds())->toBe($expected)
        ->and($ctx->hasRole(ViewerRole::Teacher))->toBeTrue()
        ->and($ctx->hasRole(ViewerRole::Parent))->toBeTrue();
});

it('ignores relations whose active_to is in the past', function () {
    $teacher = userWithRole('teacher');
    $former = userWithRole('student');
    $current = userWithRole('student');

    relate($teacher, $former, RelationType::Teacher, new DateTimeImmutable('-1 day'));
    relate($teacher, $current, RelationType::Teacher);

    $ctx = resolver()->resolve(new UserId($teacher->id));

    expect($ctx->canSeeUser($current->id))->toBeTrue()
        ->and($ctx->canSeeUser($former->id))->toBeFalse();
});

it('returns read-only self context for unknown user', function () {
    $ctx = resolver()->resolve(new UserId(999999));

    expect($ctx->visibleUserIds())->toBe([999999])
        ->and($ctx->roles)->toBe([])
        ->and($ctx->isReadOnly())->toBeTrue();
});
