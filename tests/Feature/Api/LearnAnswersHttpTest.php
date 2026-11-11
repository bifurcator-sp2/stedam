<?php

declare(strict_types=1);

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function httpRoleUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    /** @var User $user */
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function httpRelate(User $teacher, User $student): void
{
    DB::table('user_relations')->insert([
        'parent_user_id' => $teacher->id,
        'child_user_id'  => $student->id,
        'relation'       => \App\Application\Learn\Context\RelationType::Teacher->value,
        'active_from'    => null,
        'active_to'      => null,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);
}

function httpLesson(User $owner): Lesson
{
    // Таксономия с РЕАЛЬНЫМ resolver-путём из GeneratorDiscovery
    // (app/Domain/Blocks/TestGenerator/Generators/Math/Arithmetic/Operations/AdditionGenerator.php).
    // Без неё EloquentProcedureResolverPath::resolveForLesson() вернёт null,
    // и прохождение получит пустой набор заданий (LearnQuestions::empty()).
    $taxonomy = \App\Models\Taxonomy::query()->create([
        'parent_id'     => -1,
        'user_id'       => $owner->id,
        'order'         => 0,
        'test_resolver' => 'math.arithmetic.operations.addition',
    ]);

    $lesson = new Lesson();
    $lesson->user_id = $owner->id;
    $lesson->title = 'Lesson';
    $lesson->locale = 'ru';
    $lesson->published = true;
    $lesson->save();

    $lesson->taxonomies()->attach($taxonomy->id);

    // Два процедурных блока → два задания с index = 1 и 2
    // (инвариант §8.1: ответ обязан соответствовать заданию).
    \App\Models\LessonBlock::query()->create([
        'lesson_id' => $lesson->id,
        'order'     => 0,
        'is_proc'   => true,
        'complexity' => 0.5,
        'settings'  => [],
    ]);
    \App\Models\LessonBlock::query()->create([
        'lesson_id' => $lesson->id,
        'order'     => 1,
        'is_proc'   => true,
        'complexity' => 0.5,
        'settings'  => [],
    ]);

    return $lesson;
}

it('student saves images and teacher sees them as {index,url} after grading', function () {
    Storage::fake('s3_public');

    $teacher = httpRoleUser('teacher');
    $student = httpRoleUser('student');
    httpRelate($teacher, $student);
    $lesson = httpLesson($teacher);

    // Преподаватель видит ученика, ученик может стартовать своё.
    Sanctum::actingAs($student);

    $start = $this->postJson('/api/learn/begin', ['lesson_id' => $lesson->id])
        ->assertCreated();
    $learnId = $start->json('id');
    expect($learnId)->toBeInt();

    // Автосохранение изображений (multipart).
    $this->post("/api/learns/{$learnId}/answers", [
        '_method' => 'PATCH',
        'answers' => [
            ['index' => 1, 'img' => UploadedFile::fake()->image('a1.png', 400, 300)],
            ['index' => 2, 'img' => UploadedFile::fake()->image('a2.png', 400, 300)],
        ],
    ], ['Accept' => 'application/json'])->assertOk();

    // Завершение (без новых файлов — берёт автосохранённые).
    $this->postJson("/api/learns/{$learnId}/done")
        ->assertOk()
        ->assertJsonPath('status', 'done');

    // Учитель видит ответы детей с url.
    Sanctum::actingAs($teacher);
    $res = $this->getJson("/api/learns/{$learnId}")->assertOk();

    $answers = $res->json('answers');
    expect($answers)->toHaveCount(2)
        ->and($answers[0])->toHaveKeys(['index', 'url'])
        ->and($answers[0]['index'])->toBe(1)
        ->and($answers[0]['url'])->toContain('answers/')
        ->and($answers[0]['url'])->toContain('/1.webp')
        ->and($answers[1]['index'])->toBe(2);

    // Файлы реально лежат в публичном хранилище.
    Storage::disk('s3_public')->assertExists("answers/".str_pad((string) ($learnId % 1000), 3, '0', STR_PAD_LEFT)."/{$learnId}/1.webp");
});
