<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\Tasks\Ports\TaskRepositoryInterface;
use App\Domain\Lessons\ValueObjects\BlockId;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\TaxonomyId;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Domain\Tasks\Entities\Task;
use App\Domain\Tasks\Exceptions\TaskNotFound;
use App\Domain\Tasks\ValueObjects\TaskId;
use App\Models\Lesson as LessonModel;
use App\Models\LessonBlock as LessonBlockModel;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-реализация порта TaskRepositoryInterface.
 *
 * Task — это «представление» строки lesson_blocks (lesson_id IS NULL),
 * оптимизированное под смысл «обычное задание»: глобальность, таксономии,
 * complexity.
 *
 * Защитная проверка: методы here не проверяют is_proc (это делает
 * домен TasksProc через ProcTaskRepository). Если в TaskRepository
 * попадёт proc-строка, это будет багом в вызывающем коде — поэтому
 * проверяем явно.
 */
final class EloquentTaskRepository implements TaskRepositoryInterface
{
    public function save(Task $task): Task
    {
        $model = $task->id() !== null
            ? LessonBlockModel::query()->find($task->id()->value)
            : null;

        if ($model === null && $task->id() !== null) {
            throw TaskNotFound::withId($task->id()->value);
        }

        if ($model === null) {
            $model = new LessonBlockModel();
            $model->lesson_id = null; // задание глобально
        }

        $model->user_id    = $task->userId()->value;
        $model->block_id   = $task->blockId()->value;
        $model->settings   = $task->settings();
        $model->complexity = $task->complexity()?->value;
        $model->is_proc    = false;
        $model->save();

        if ($task->id() === null) {
            // Таксономии — только после появления id.
            $this->syncTaxonomies((int) $model->id, $task->taxonomyIds());

            return $task->withId(new TaskId((int) $model->id));
        }

        return $task;
    }

    public function getById(TaskId $id): Task
    {
        $task = $this->findById($id);

        if ($task === null) {
            throw TaskNotFound::withId($id->value);
        }

        return $task;
    }

    public function findById(TaskId $id): ?Task
    {
        $model = LessonBlockModel::query()
            ->whereKey($id->value)
            ->whereNull('lesson_id')
            ->where('is_proc', false)
            ->first();

        if ($model === null) {
            return null;
        }

        $taxonomyIds = $model->taxonomies()
            ->pluck('taxonomies.id')
            ->map(static fn ($v): TaxonomyId => new TaxonomyId((int) $v))
            ->all();

        return Task::fromPersistence(
            id: new TaskId((int) $model->id),
            userId: new UserId((int) $model->user_id),
            blockId: new BlockId((int) $model->block_id),
            settings: is_array($model->settings) ? $model->settings : [],
            complexity: Complexity::fromNullable(
                $model->complexity !== null ? (float) $model->complexity : null
            ),
            taxonomyIds: $taxonomyIds,
        );
    }

    public function isUsedInLessons(TaskId $id): bool
    {
        return DB::table('lesson_lesson_block_as_test')
            ->where('lesson_block_id', $id->value)
            ->exists();
    }

    public function pluckUsageLessonIds(TaskId $id): array
    {
        return DB::table('lesson_lesson_block_as_test')
            ->where('lesson_block_id', $id->value)
            ->pluck('lesson_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    public function recalculateComplexityOfUsages(TaskId $id): array
    {
        $touched = [];

        foreach ($this->pluckUsageLessonIds($id) as $lessonId) {
            $lesson = LessonModel::query()->find($lessonId);

            if ($lesson === null) {
                continue;
            }

            $lesson->recalculateComplexity();
            $lesson->saveQuietly();
            $touched[] = $lessonId;
        }

        return $touched;
    }

    public function delete(TaskId $id): void
    {
        $model = LessonBlockModel::query()->find($id->value);
        $model?->delete();
    }

    /**
     * @return list<LessonId>
     */
    public function detachFromAllLessons(TaskId $id): array
    {
        $lessonIds = $this->pluckUsageLessonIds($id);

        DB::table('lesson_lesson_block_as_test')
            ->where('lesson_block_id', $id->value)
            ->delete();

        // Пересчёт после отвязки — теперь в уроках меньше заданий.
        foreach ($lessonIds as $lessonId) {
            $lesson = LessonModel::query()->find($lessonId);
            if ($lesson === null) {
                continue;
            }
            $lesson->recalculateComplexity();
            $lesson->saveQuietly();
        }

        return array_map(static fn (int $v): LessonId => new LessonId($v), $lessonIds);
    }

    /**
     * @param list<TaxonomyId> $taxonomyIds
     */
    private function syncTaxonomies(int $blockId, array $taxonomyIds): void
    {
        $model = LessonBlockModel::query()->find($blockId);

        if ($model === null) {
            return;
        }

        $ids = array_map(static fn (TaxonomyId $t): int => $t->value, $taxonomyIds);

        if ($ids === []) {
            $model->taxonomies()->detach();
            return;
        }

        $model->taxonomies()->sync($ids);
    }
}
