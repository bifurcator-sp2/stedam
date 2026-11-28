<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Хелпер: ребро графа владения/наблюдения.
 */
function communityUserRelate(
    User $parent,
    User $child,
    string $relation,
    ?DateTimeImmutable $activeTo = null,
): void {
    DB::table('user_relations')->insert([
        'parent_user_id' => $parent->id,
        'child_user_id' => $child->id,
        'relation' => $relation,
        'active_from' => null,
        'active_to' => $activeTo?->format('Y-m-d H:i:sP'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Spatie-роль + назначение пользователю.
 */
function communityUserWithRole(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);

    /** @var User $user */
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('lists the students of the current teacher', function () {
    $teacher = communityUserWithRole('teacher');
    $studentA = communityUserWithRole('student');
    $studentB = communityUserWithRole('student');
    $stranger = communityUserWithRole('student');

    communityUserRelate($teacher, $studentA, 'teacher');
    communityUserRelate($teacher, $studentB, 'teacher');
    // Не моя связь — в выдачу не попадает.
    communityUserRelate($stranger, $studentA, 'teacher');

    $this->actingAs($teacher)
        ->getJson('/api/users?relation=teacher&counterpart_role=student&page=1&per_page=20')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.has_more_pages', false)
        ->assertJsonPath('data.0.id', $studentB->id)
        ->assertJsonPath('data.1.id', $studentA->id);
});

it('searches within the related users only', function () {
    $teacher = communityUserWithRole('teacher');
    $matched = communityUserWithRole('student');
    $other = communityUserWithRole('student');
    $notRelated = communityUserWithRole('student');

    $matched->userData()->create(['first_name' => 'Иван', 'last_name' => 'Петров']);
    $other->userData()->create(['first_name' => 'Пётр', 'last_name' => 'Сидоров']);
    $notRelated->userData()->create(['first_name' => 'Иван', 'last_name' => 'Иванов']);

    communityUserRelate($teacher, $matched, 'teacher');
    communityUserRelate($teacher, $other, 'teacher');

    $this->actingAs($teacher)
        ->getJson('/api/users?search=иван&relation=teacher&counterpart_role=student')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $matched->id)
        ->assertJsonPath('data.0.first_name', 'Иван')
        ->assertJsonPath('data.0.last_name', 'Петров');

    // Поиск по id тоже ограничен своей связью.
    $this->actingAs($teacher)
        ->getJson('/api/users?search='.$notRelated->id.'&relation=teacher&counterpart_role=student')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns an empty list for unknown relation codes', function () {
    $teacher = communityUserWithRole('teacher');
    $student = communityUserWithRole('student');
    communityUserRelate($teacher, $student, 'teacher');

    $this->actingAs($teacher)
        ->getJson('/api/users?relation=unknown&counterpart_role=student')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Роль на другом конце не соответствует типу ребра.
    $this->actingAs($teacher)
        ->getJson('/api/users?relation=teacher&counterpart_role=parent')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('ignores ended links', function () {
    $teacher = communityUserWithRole('teacher');
    $student = communityUserWithRole('student');
    communityUserRelate($teacher, $student, 'teacher', new DateTimeImmutable('-1 day'));

    $this->actingAs($teacher)
        ->getJson('/api/users?relation=teacher&counterpart_role=student')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('lists managers of the current teacher in incoming direction', function () {
    $methodologist = communityUserWithRole('methodologist');
    $teacher = communityUserWithRole('teacher');
    communityUserRelate($methodologist, $teacher, 'methodologist');

    $this->actingAs($teacher)
        ->getJson('/api/users?direction=incoming&relation=methodologist&counterpart_role=methodologist')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $methodologist->id);
});

it('filters related users by required roles', function () {
    $teacher = communityUserWithRole('teacher');
    $studentParent = communityUserWithRole('student');
    $studentParent->assignRole('parent');
    $studentOnly = communityUserWithRole('student');

    communityUserRelate($teacher, $studentParent, 'teacher');
    communityUserRelate($teacher, $studentOnly, 'teacher');

    // Ученики, которые при этом являются родителями.
    $this->actingAs($teacher)
        ->getJson('/api/users?relation=teacher&counterpart_role=student&roles[]=parent')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $studentParent->id);

    // Пользователь должен обладать всеми ролями из списка.
    $this->actingAs($teacher)
        ->getJson('/api/users?relation=teacher&counterpart_role=student&roles[]=student&roles[]=parent')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $studentParent->id);

    $this->actingAs($teacher)
        ->getJson('/api/users?relation=teacher&counterpart_role=student&roles[]=student&roles[]=methodologist')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters users by roles without a relation', function () {
    $viewer = communityUserWithRole('methodologist');
    $parent = communityUserWithRole('parent');
    communityUserWithRole('student');

    $this->actingAs($viewer)
        ->getJson('/api/users?roles[]=parent')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $parent->id);
});

it('ignores relation or counterpart_role when given alone', function () {
    $teacher = communityUserWithRole('teacher');
    $student = communityUserWithRole('student');
    communityUserRelate($teacher, $student, 'teacher');

    // Только relation — связь не применяется, отдаются все пользователи.
    $this->actingAs($teacher)
        ->getJson('/api/users?relation=teacher')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Только counterpart_role — аналогично.
    $this->actingAs($teacher)
        ->getJson('/api/users?counterpart_role=student')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('requires authentication', function () {
    $this->getJson('/api/users?relation=teacher&counterpart_role=student')
        ->assertUnauthorized();
});
