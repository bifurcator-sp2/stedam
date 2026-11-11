<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\Lessons\Ports\LessonRepositoryInterface;
use App\Domain\Lessons\Entities\Lesson as LessonEntity;
use App\Domain\Lessons\Exceptions\LessonNotFound;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\TaxonomyId;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Infrastructure\Persistence\Mappers\LessonMapper;
use App\Models\Lesson as LessonModel;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-реализация порта App\Application\Lessons\Ports\LessonRepositoryInterface.
 *
 * Назван «Aggregate» намеренно: в проекте уже есть EloquentLessonRepository
 * для Learn-контекста (read-only «опубликован ли»). Смешивать два разных
 * смысла «репозитория урока» в одном классе нельзя.
 */
final class EloquentLessonAggregateRepository implements LessonRepositoryInterface
{
    public function save(LessonEntity $lesson): LessonEntity
    {
        $model = $lesson->id() !== null
            ? LessonModel::query()->find($lesson->id()->value)
            : null;

        if ($model === null && $lesson->id() !== null) {
            throw LessonNotFound::withId($lesson->id()->value);
        }

        if ($model === null) {
            $model = new LessonModel();
            $model->user_id = $lesson->userId()->value;
        }

        LessonMapper::applyToModel($lesson, $model);

        DB::transaction(function () use ($lesson, $model) {
            $model->save();

            if ($lesson->id() !== null) {
                $this->syncTaxonomies($model, $lesson->taxonomyIds());
            }
        });

        if ($lesson->id() === null) {
            return $lesson->withId(new LessonId((int) $model->id));
        }

        return $lesson;
    }

    public function getById(LessonId $id): LessonEntity
    {
        $lesson = $this->findById($id);

        if ($lesson === null) {
            throw LessonNotFound::withId($id->value);
        }

        return $lesson;
    }

    public function findById(LessonId $id): ?LessonEntity
    {
        $model = LessonModel::query()
            ->with(['taxonomies:id'])
            ->find($id->value);

        return $model ? LessonMapper::toDomain($model) : null;
    }

    public function findOwnerId(LessonId $id): ?UserId
    {
        $ownerId = LessonModel::query()->whereKey($id->value)->value('user_id');

        return $ownerId === null ? null : new UserId((int) $ownerId);
    }

    public function taxonomyIdsOf(LessonId $id): array
    {
        $model = LessonModel::query()->find($id->value);

        if ($model === null) {
            return [];
        }

        return $model->taxonomies()
            ->pluck('taxonomies.id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * Рекурсивный спуск по parent_id (PostgreSQL: WITH RECURSIVE).
     *
     * @param list<int> $taxonomyIds
     * @return list<int>
     */
    public function expandTaxonomiesWithDescendants(array $taxonomyIds): array
    {
        if ($taxonomyIds === []) {
            return [];
        }

        $idList = implode(',', array_map('intval', $taxonomyIds));

        $rows = DB::select(
            "WITH RECURSIVE descendants AS (
                SELECT id FROM taxonomies WHERE id IN ({$idList})
                UNION
                SELECT t.id
                FROM taxonomies t
                JOIN descendants d ON t.parent_id = d.id
            )
            SELECT DISTINCT id FROM descendants"
        );

        return array_map(static fn ($row): int => (int) $row->id, $rows);
    }

    /**
     * @param list<int> $lessonIds
     * @return list<int>
     */
    public function pluckUserOwnedLessonIds(UserId $userId, array $lessonIds): array
    {
        if ($lessonIds === []) {
            return [];
        }

        return LessonModel::query()
            ->whereIn('id', $lessonIds)
            ->where('user_id', $userId->value)
            ->pluck('id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * @param list<TaxonomyId> $taxonomyIds
     */
    private function syncTaxonomies(LessonModel $model, array $taxonomyIds): void
    {
        $ids = array_map(static fn (TaxonomyId $t): int => $t->value, $taxonomyIds);

        if ($ids === []) {
            $model->taxonomies()->detach();
            return;
        }

        $model->taxonomies()->sync($ids);
    }
}
