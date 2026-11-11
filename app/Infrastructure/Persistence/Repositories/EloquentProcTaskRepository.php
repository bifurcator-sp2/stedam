<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\TasksProc\Ports\ProcTaskRepositoryInterface;
use App\Domain\Lessons\ValueObjects\BlockId;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Domain\TasksProc\Entities\ProcTask;
use App\Domain\TasksProc\Exceptions\ProcTaskKindMismatch;
use App\Domain\TasksProc\Exceptions\ProcTaskNotFound;
use App\Domain\TasksProc\ValueObjects\ProcTaskId;
use App\Models\Lesson as LessonModel;
use App\Models\LessonBlock as LessonBlockModel;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-реализация порта ProcTaskRepositoryInterface.
 *
 * Proc-задание — строка lesson_blocks с lesson_id = NULL и is_proc = true.
 * При попытке работать с non-proc строкой как с ProcTask — бросаем
 * ProcTaskKindMismatch.
 */
final class EloquentProcTaskRepository implements ProcTaskRepositoryInterface
{
    public function save(ProcTask $task): ProcTask
    {
        $model = $task->id() !== null
            ? LessonBlockModel::query()->find($task->id()->value)
            : null;

        if ($model === null && $task->id() !== null) {
            throw ProcTaskNotFound::withId($task->id()->value);
        }

        if ($model === null) {
            $model = new LessonBlockModel();
            $model->lesson_id = null;
        }

        if ($task->id() !== null && ! (bool) $model->is_proc) {
            throw ProcTaskKindMismatch::withId($task->id()->value);
        }

        $model->user_id    = $task->userId()->value;
        $model->block_id   = $task->blockId()->value;
        $model->settings   = $task->settings();
        $model->complexity = $task->complexity()?->value;
        $model->is_proc    = true;
        $model->save();

        if ($task->id() === null) {
            return $task->withId(new ProcTaskId((int) $model->id));
        }

        return $task;
    }

    public function getById(ProcTaskId $id): ProcTask
    {
        $task = $this->findById($id);

        if ($task === null) {
            throw ProcTaskNotFound::withId($id->value);
        }

        return $task;
    }

    public function findById(ProcTaskId $id): ?ProcTask
    {
        $model = LessonBlockModel::query()
            ->whereKey($id->value)
            ->whereNull('lesson_id')
            ->where('is_proc', true)
            ->first();

        if ($model === null) {
            return null;
        }

        return ProcTask::fromPersistence(
            id: new ProcTaskId((int) $model->id),
            userId: new UserId((int) $model->user_id),
            blockId: new BlockId((int) $model->block_id),
            settings: is_array($model->settings) ? $model->settings : [],
            complexity: Complexity::fromNullable(
                $model->complexity !== null ? (float) $model->complexity : null
            ),
        );
    }

    public function recalculateComplexityOfUsages(ProcTaskId $id): array
    {
        $lessonIds = DB::table('lesson_lesson_block_as_test')
            ->where('lesson_block_id', $id->value)
            ->pluck('lesson_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        foreach ($lessonIds as $lessonId) {
            $lesson = LessonModel::query()->find($lessonId);
            if ($lesson === null) {
                continue;
            }
            $lesson->recalculateComplexity();
            $lesson->saveQuietly();
        }

        return $lessonIds;
    }
}
