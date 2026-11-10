<?php

declare(strict_types=1);

use App\Application\Learn\Actions\ListTeacherStudentsAction;
use App\Application\Learn\Context\RelationType;
use App\Application\Learn\DTOs\StudentSummary;
use App\Domain\Learn\ValueObjects\UserId;
use App\Infrastructure\Persistence\Context\EloquentContextResolver;
use App\Infrastructure\Persistence\Queries\EloquentTeacherStudentsQuery;
use App\Models\Learn as LearnModel;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function userWithRole2(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    /** @var User $user */
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function relate2(User $parent, User $child, RelationType $relation): void
{
    DB::table('user_relations')->insert([
        'parent_user_id' => $parent->id,
        'child_user_id'  => $child->id,
        'relation'       => $relation->value,
        'active_from'    => null,
        'active_to'      => null,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
}

function createLessonRow(User $owner): Lesson
{
    $lesson = new Lesson();
    $lesson->user_id = $owner->id;
    $lesson->title = 'L';
    $lesson->locale = 'ru';
    $lesson->published = true;
    $lesson->save();

    return $lesson;
}

function createLearnRow(User $student, Lesson $lesson, string $status, ?float $score = null): void
{
    $data = [
        'user_id'     => $student->id,
        'lesson_id'   => $lesson->id,
        'status'      => $status,
        'status_date' => now(),
    ];

    // score — NOT NULL DEFAULT 0; не пишем null явно.
    if ($score !== null) {
        $data['score'] = $score;
    }

    LearnModel::create($data);
}

function listStudents(User $teacher, ?string $search = null, int $page = 1, int $perPage = 20)
{
    $action = new ListTeacherStudentsAction(
        new EloquentTeacherStudentsQuery(),
        new EloquentContextResolver(),
    );

    return $action(new UserId($teacher->id), $search, $page, $perPage);
}

it('lists only teacher students (not strangers, not self)', function () {
    $teacher = userWithRole2('teacher');
    $s1 = userWithRole2('student');
    $s2 = userWithRole2('student');
    $stranger = userWithRole2('student');

    relate2($teacher, $s1, RelationType::Teaches);
    relate2($teacher, $s2, RelationType::Teaches);

    $result = listStudents($teacher);

    $ids = array_map(static fn (StudentSummary $s) => $s->id, $result->items);
    sort($ids);

    expect($ids)->toBe([$s1->id, $s2->id])
        ->and($ids)->not->toContain($teacher->id)
        ->and($ids)->not->toContain($stranger->id);
});

it('computes aggregates per student', function () {
    $teacher = userWithRole2('teacher');
    $s = userWithRole2('student');
    relate2($teacher, $s, RelationType::Teaches);

    $lesson = createLessonRow($teacher);
    createLearnRow($s, $lesson, 'in_progress');
    createLearnRow($s, $lesson, 'done');
    createLearnRow($s, $lesson, 'graded', 80.0);
    createLearnRow($s, $lesson, 'graded', 60.0);

    $result = listStudents($teacher);
    /** @var StudentSummary $summary */
    $summary = $result->items[0];

    expect($summary->learnsCount)->toBe(4)
        ->and($summary->inProgress)->toBe(1)
        ->and($summary->awaitingGrade)->toBe(1)
        ->and($summary->graded)->toBe(2)
        ->and($summary->avgScore)->toBe(70.0);
});

it('filters by search', function () {
    $teacher = userWithRole2('teacher');
    $s1 = userWithRole2('student');
    $s1->name = 'Alice Ivanova';
    $s1->save();

    $s2 = userWithRole2('student');
    $s2->name = 'Bob Petrov';
    $s2->save();

    relate2($teacher, $s1, RelationType::Teaches);
    relate2($teacher, $s2, RelationType::Teaches);

    $result = listStudents($teacher, 'Alice');

    expect($result->total)->toBe(1)
        ->and($result->items[0]->id)->toBe($s1->id);
});

it('denies non-teacher', function () {
    $student = userWithRole2('student');

    expect(fn () => listStudents($student))
        ->toThrow(\App\Application\Learn\Exceptions\LearnAccessDenied::class);
});
