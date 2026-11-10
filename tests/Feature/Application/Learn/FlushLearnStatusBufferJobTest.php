<?php

declare(strict_types=1);

use App\Jobs\FlushLearnStatusBufferJob;
use App\Models\Learn as LearnModel;
use App\Models\LearnStatus as LearnStatusModel;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fakes\InMemoryLearnStatusBuffer;

uses(RefreshDatabase::class);

/**
 * Готовит минимальные lesson + learn, возвращает id learn/lesson.
 *
 * @return array{learnId: int, lessonId: int}
 */
function seedLearn(): array
{
    $student = User::factory()->create();

    $lesson = new Lesson();
    $lesson->user_id = $student->id;
    $lesson->title = 'Test lesson';
    $lesson->locale = 'ru';
    $lesson->published = true;
    $lesson->save();

    $learn = LearnModel::create([
        'user_id'      => $student->id,
        'lesson_id'    => $lesson->id,
        'status'       => 'in_progress',
        'status_date'  => now(),
    ]);

    return ['learnId' => $learn->id, 'lessonId' => $lesson->id];
}

function statusEvent(int $learnId, int $lessonId, string $status): array
{
    return [
        'learn_id'      => $learnId,
        'lesson_id'     => $lessonId,
        'status'        => $status,
        'status_date'   => '2025-01-01T12:00:00+00:00',
        'schedule_date' => null,
    ];
}

it('flushes buffered events into learn_statuses', function () {
    ['learnId' => $learnId, 'lessonId' => $lessonId] = seedLearn();

    $buffer = new InMemoryLearnStatusBuffer();
    $buffer->push(statusEvent($learnId, $lessonId, 'in_progress'));
    $buffer->push(statusEvent($learnId, $lessonId, 'done'));

    (new FlushLearnStatusBufferJob())->handle($buffer);

    expect(LearnStatusModel::query()->where('learn_id', $learnId)->count())->toBe(2)
        ->and($buffer->size())->toBe(0);

    $statuses = LearnStatusModel::query()
        ->where('learn_id', $learnId)
        ->orderBy('id')
        ->pluck('status')
        ->map(static fn ($s) => $s->value)
        ->all();

    expect($statuses)->toBe(['in_progress', 'done']);
});

it('respects maxEvents limit and leaves the rest buffered', function () {
    ['learnId' => $learnId, 'lessonId' => $lessonId] = seedLearn();

    $buffer = new InMemoryLearnStatusBuffer();
    foreach (['in_progress', 'done', 'graded'] as $s) {
        $buffer->push(statusEvent($learnId, $lessonId, $s));
    }

    (new FlushLearnStatusBufferJob(maxEvents: 2))->handle($buffer);

    expect(LearnStatusModel::query()->where('learn_id', $learnId)->count())->toBe(2)
        ->and($buffer->size())->toBe(1);
});

it('does nothing when buffer is empty', function () {
    $buffer = new InMemoryLearnStatusBuffer();

    (new FlushLearnStatusBufferJob())->handle($buffer);

    expect(LearnStatusModel::query()->count())->toBe(0);
});
