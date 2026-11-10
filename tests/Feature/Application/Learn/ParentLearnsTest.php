<?php

declare(strict_types=1);

use App\Application\Learn\Actions\ListParentChildrenAction;
use App\Application\Learn\Actions\ListParentLearnsAction;
use App\Application\Learn\Context\RelationType;
use App\Application\Learn\DTOs\LearnListFilter;
use App\Application\Learn\DTOs\StudentSummary;
use App\Domain\Learn\ValueObjects\UserId;
use App\Infrastructure\Persistence\Context\EloquentContextResolver;
use App\Infrastructure\Persistence\Queries\EloquentTeacherStudentsQuery;
use App\Infrastructure\Persistence\Repositories\EloquentLearnRepository;
use App\Models\Learn as LearnModel;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function parentUserWithRole(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    /** @var User $user */
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function parentRelate(User $parent, User $child): void
{
    DB::table('user_relations')->insert([
        'parent_user_id' => $parent->id,
        'child_user_id'  => $child->id,
        'relation'       => RelationType::Parents->value,
        'active_from'    => null,
        'active_to'      => null,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
}

function parentLesson(User $owner): Lesson
{
    $lesson = new Lesson();
    $lesson->user_id = $owner->id;
    $lesson->title = 'L';
    $lesson->locale = 'ru';
    $lesson->published = true;
    $lesson->save();

    return $lesson;
}

function parentLearn(User $student, Lesson $lesson, string $status): void
{
    LearnModel::create([
        'user_id'         => $student->id,
        'lesson_id'       => $lesson->id,
        'lesson_snapshot' => str_repeat('a', 64),
        'status'          => $status,
        'status_date'     => now(),
    ]);
}

function listChildren(User $parent, ?string $search = null)
{
    $action = new ListParentChildrenAction(
        new EloquentTeacherStudentsQuery(),
        new EloquentContextResolver(),
    );

    return $action(new UserId($parent->id), $search, 1, 20);
}

function listParentLearns(User $parent)
{
    $action = new ListParentLearnsAction(
        new EloquentLearnRepository(),
        new EloquentContextResolver(),
    );

    return $action(new UserId($parent->id), LearnListFilter::default());
}

it('parent sees only own children with aggregates', function () {
    $parent = parentUserWithRole('parent');
    $parent2 = parentUserWithRole('parent');
    $kid = parentUserWithRole('student');
    $kid2 = parentUserWithRole('student');
    $strangerKid = parentUserWithRole('student');

    parentRelate($parent, $kid);
    parentRelate($parent, $kid2);
    parentRelate($parent2, $strangerKid);

    $result = listChildren($parent);
    $ids = array_map(static fn (StudentSummary $s) => $s->id, $result->items);
    sort($ids);

    expect($ids)->toBe([$kid->id, $kid2->id])
        ->and($ids)->not->toContain($strangerKid->id)
        ->and($ids)->not->toContain($parent->id);
});

it('parent children aggregates reflect learns', function () {
    $parent = parentUserWithRole('parent');
    $kid = parentUserWithRole('student');
    parentRelate($parent, $kid);

    $lesson = parentLesson($parent);
    parentLearn($kid, $lesson, 'in_progress');
    parentLearn($kid, $lesson, 'done');

    $result = listChildren($parent);
    /** @var StudentSummary $summary */
    $summary = $result->items[0];

    expect($summary->learnsCount)->toBe(2)
        ->and($summary->inProgress)->toBe(1)
        ->and($summary->awaitingGrade)->toBe(1);
});

it('parent learns list contains only children learns, not own', function () {
    $parent = parentUserWithRole('parent');
    $kid = parentUserWithRole('student');
    parentRelate($parent, $kid);

    $lesson = parentLesson($parent);
    parentLearn($kid, $lesson, 'in_progress');
    parentLearn($kid, $lesson, 'done');
    // Своё прохождение родителя — не должно попасть в список.
    parentLearn($parent, $lesson, 'in_progress');

    $page = listParentLearns($parent);

    $userIds = array_map(static fn ($l) => $l->userId()->value, $page->items);
    expect($page->total)->toBe(2)
        ->and($userIds)->not->toContain($parent->id);
});

it('denies non-parent for children list', function () {
    $student = parentUserWithRole('student');

    expect(fn () => listChildren($student))
        ->toThrow(\App\Application\Learn\Exceptions\LearnAccessDenied::class);
});

it('denies non-parent for learns list', function () {
    $student = parentUserWithRole('student');

    expect(fn () => listParentLearns($student))
        ->toThrow(\App\Application\Learn\Exceptions\LearnAccessDenied::class);
});
