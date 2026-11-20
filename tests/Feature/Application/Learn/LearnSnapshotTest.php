<?php

declare(strict_types=1);

use App\Application\Learn\Ports\SnapshotReaderInterface;
use App\Application\Learn\Ports\SnapshotResolverInterface;
use App\Domain\Learn\Exceptions\SnapshotMissing;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\SnapshotHash;
use App\Models\Learn as LearnModel;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Читатель снапшота из памяти: отдаёт заранее заданный JSON по любому хэшу.
 */
function snapshotTestReader(string $json): SnapshotReaderInterface
{
    return new class ($json) implements SnapshotReaderInterface {
        public function __construct(private readonly string $json) {}

        public function read(SnapshotHash $hash): string
        {
            return $this->json;
        }
    };
}

function snapshotTestStudent(): User
{
    Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    /** @var User $user */
    $user = User::factory()->create();
    $user->assignRole('student');

    return $user;
}

function snapshotTestLesson(User $owner, float $complexity, int $taxonomyCount): Lesson
{
    $lesson = new Lesson();
    $lesson->user_id = $owner->id;
    $lesson->title = 'L';
    $lesson->description = 'Текущее описание урока';
    $lesson->locale = 'ru';
    $lesson->type = 'class_work';
    $lesson->published = true;
    $lesson->save();

    DB::table('lessons')->where('id', $lesson->id)->update(['complexity' => $complexity]);

    // У урока одна таксономия (lesson_taxonomy.lesson_id — unique).
    for ($i = 0; $i < $taxonomyCount; $i++) {
        $taxonomyId = DB::table('taxonomies')->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($i > 0) {
            continue;
        }

        DB::table('lesson_taxonomy')->insert([
            'lesson_id'   => $lesson->id,
            'taxonomy_id' => $taxonomyId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    return $lesson;
}

function snapshotTestLearn(User $student, Lesson $lesson, string $status = 'in_progress'): LearnModel
{
    return LearnModel::create([
        'user_id'         => $student->id,
        'lesson_id'       => $lesson->id,
        'lesson_snapshot' => str_repeat('a', 64),
        'status'          => $status,
        'status_date'     => now(),
    ]);
}

it('returns type/complexity/taxonomy_ids from snapshot as-is', function () {
    $student = snapshotTestStudent();
    $lesson = snapshotTestLesson($student, 4.5, 1);
    $learn = snapshotTestLearn($student, $lesson);

    $snapshotJson = json_encode([
        'lesson_id'    => $lesson->id,
        'title'        => 'L',
        'description'  => 'Описание из снапшота',
        'locale'       => 'ru',
        'type'         => 'class_work',
        'complexity'   => 2.0,
        'taxonomy_ids' => [999],
        'blocks'       => [],
    ]);

    $this->app->instance(SnapshotReaderInterface::class, snapshotTestReader($snapshotJson));
    Sanctum::actingAs($student);

    $response = $this->getJson("/api/learn/{$learn->id}/snapshot")->assertOk();

    $response->assertJsonPath('snapshot_data.description', 'Описание из снапшота')
        ->assertJsonPath('snapshot_data.type', 'class_work')
        ->assertJsonPath('snapshot_data.complexity', 2)
        ->assertJsonPath('snapshot_data.taxonomy_ids', [999]);
});

it('falls back to current lesson for old snapshots without metadata', function () {
    $student = snapshotTestStudent();
    $lesson = snapshotTestLesson($student, 4.5, 1);
    $learn = snapshotTestLearn($student, $lesson);

    $snapshotJson = json_encode([
        'lesson_id' => $lesson->id,
        'title'     => 'L',
        'locale'    => 'ru',
        'blocks'    => [],
    ]);

    $this->app->instance(SnapshotReaderInterface::class, snapshotTestReader($snapshotJson));
    Sanctum::actingAs($student);

    $response = $this->getJson("/api/learn/{$learn->id}/snapshot")->assertOk();

    $taxonomyIds = DB::table('lesson_taxonomy')->where('lesson_id', $lesson->id)->pluck('taxonomy_id');

    $response->assertJsonPath('snapshot_data.description', 'Текущее описание урока')
        ->assertJsonPath('snapshot_data.type', 'class_work')
        ->assertJsonPath('snapshot_data.complexity', 4.5)
        ->assertJsonPath('snapshot_data.taxonomy_ids', $taxonomyIds->all());
});

it('restores a deleted snapshot file when the lesson is unchanged', function () {
    $student = snapshotTestStudent();
    $lesson = snapshotTestLesson($student, 1.0, 1);

    // Реальный резолвер (S3-режим на фейковом диске) строит снапшот урока.
    Storage::fake('s3_private');
    config(['learn.snapshot.driver' => 's3', 'learn.snapshot.disk' => 's3_private']);
    $this->app->forgetInstance(SnapshotResolverInterface::class);
    $this->app->forgetInstance(SnapshotReaderInterface::class);

    $resolver = $this->app->make(SnapshotResolverInterface::class);
    $hash = $resolver->ensureFor(new LessonId($lesson->id));

    $learn = snapshotTestLearn($student, $lesson);
    DB::table('learns')->where('id', $learn->id)->update(['lesson_snapshot' => $hash->value]);

    $path = 'learns/snapshots/'.$hash->shard().'/'.$hash->value.'.json';
    Storage::disk('s3_private')->assertExists($path);

    // Файл удалён (например, при чистке хранилища) — читатель его не найдёт.
    Storage::disk('s3_private')->delete($path);
    Storage::disk('s3_private')->assertMissing($path);

    Sanctum::actingAs($student);

    $this->getJson("/api/learn/{$learn->id}/snapshot")
        ->assertOk()
        ->assertJsonPath('snapshot_data.lesson_id', $lesson->id);

    // Файл пересоздан на месте.
    Storage::disk('s3_private')->assertExists($path);
});

it('does not serve a different version when the lesson changed since start', function () {
    $student = snapshotTestStudent();
    $lesson = snapshotTestLesson($student, 1.0, 1);

    Storage::fake('s3_private');
    config(['learn.snapshot.driver' => 's3', 'learn.snapshot.disk' => 's3_private']);
    $this->app->forgetInstance(SnapshotResolverInterface::class);
    $this->app->forgetInstance(SnapshotReaderInterface::class);

    $resolver = $this->app->make(SnapshotResolverInterface::class);
    $hash = $resolver->ensureFor(new LessonId($lesson->id));

    $learn = snapshotTestLearn($student, $lesson);
    DB::table('learns')->where('id', $learn->id)->update(['lesson_snapshot' => $hash->value]);

    // Урок изменился после старта — пересобранный снапшот даст другой хэш.
    DB::table('lessons')->where('id', $lesson->id)->update(['title' => 'Новый заголовок']);

    $path = 'learns/snapshots/'.$hash->shard().'/'.$hash->value.'.json';
    Storage::disk('s3_private')->delete($path);

    Sanctum::actingAs($student);

    // Отдать чужую версию нельзя — ошибка остаётся.
    $this->getJson("/api/learn/{$learn->id}/snapshot")->assertStatus(500);
});
