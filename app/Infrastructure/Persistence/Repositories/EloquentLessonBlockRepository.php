<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface;
use App\Domain\Blocks\Settings\BlockSettingsRegistry;
use App\Domain\Blocks\Settings\SettingsNormalizer;
use App\Domain\LessonBlocks\Entities\LessonBlock as DomainLessonBlock;
use App\Domain\LessonBlocks\Exceptions\LessonBlockNotFound;
use App\Domain\LessonBlocks\ValueObjects\LessonBlockId;
use App\Domain\Lessons\Services\BlockOrderPlanner;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\TaxonomyId;
use App\Infrastructure\Persistence\Mappers\LessonBlockMapper;
use App\Models\Block;
use App\Models\LessonBlock as LessonBlockModel;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-реализация порта LessonBlockRepositoryInterface.
 *
 * Единственное место, знающее о физическом устройстве:
 *   — таблица lesson_blocks;
 *   — pivot lesson_lesson_block_as_test;
 *   — pivot lesson_block_taxonomy;
 *   — двухфазный SAFE_OFFSET-сдвиг order (PostgreSQL UNIQUE).
 */
final class EloquentLessonBlockRepository implements LessonBlockRepositoryInterface
{
    // ===== Восстановление / сохранение =====

    public function save(DomainLessonBlock $block): DomainLessonBlock
    {
        $model = $block->id() !== null
            ? LessonBlockModel::query()->find($block->id()->value)
            : null;

        if ($model === null && $block->id() !== null) {
            throw LessonBlockNotFound::withId($block->id()->value);
        }

        if ($model === null) {
            $model = new LessonBlockModel();
        }

        LessonBlockMapper::applyToModel($block, $model);
        $model->save();

        if ($block->id() === null) {
            return $block->withId(new LessonBlockId((int) $model->id));
        }

        return $block;
    }

    public function getById(LessonBlockId $id): DomainLessonBlock
    {
        $block = $this->findById($id);

        if ($block === null) {
            throw LessonBlockNotFound::withId($id->value);
        }

        return $block;
    }

    public function findById(LessonBlockId $id): ?DomainLessonBlock
    {
        $model = LessonBlockModel::query()->find($id->value);

        return $model ? LessonBlockMapper::toDomain($model) : null;
    }

    // ===== Чтение состава урока =====

    public function findAllOrdered(LessonId $lessonId): array
    {
        $ownModels = LessonBlockModel::query()
            ->where('lesson_id', $lessonId->value)
            ->orderBy('order')
            ->get();

        $sharedModels = LessonBlockModel::query()
            ->join('lesson_lesson_block_as_test as p', 'p.lesson_block_id', '=', 'lesson_blocks.id')
            ->where('p.lesson_id', $lessonId->value)
            ->orderBy('p.order')
            ->select('lesson_blocks.*')
            ->addSelect('p.order as pivot_order')
            ->get();

        $own = $ownModels
            ->map(static fn (LessonBlockModel $m): DomainLessonBlock => LessonBlockMapper::toDomain($m))
            ->all();

        $shared = $sharedModels
            ->map(static function (LessonBlockModel $m): DomainLessonBlock {
                // Для привязанного задания «истина» — pivot.order.
                $m->setAttribute('order', (int) $m->getAttribute('pivot_order'));

                return LessonBlockMapper::toDomain($m);
            })
            ->all();

        $merged = array_merge($own, $shared);
        usort(
            $merged,
            static fn (DomainLessonBlock $a, DomainLessonBlock $b): int => $a->order() <=> $b->order(),
        );

        return $merged;
    }

    public function findOwn(LessonId $lessonId): array
    {
        return LessonBlockModel::query()
            ->where('lesson_id', $lessonId->value)
            ->orderBy('order')
            ->get()
            ->map(static fn (LessonBlockModel $m): DomainLessonBlock => LessonBlockMapper::toDomain($m))
            ->all();
    }

    public function pluckAttachedTaskIds(LessonId $lessonId): array
    {
        return DB::table('lesson_lesson_block_as_test')
            ->where('lesson_id', $lessonId->value)
            ->orderBy('order')
            ->pluck('lesson_block_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    // ===== Порядок / привязки =====

    public function attachToLesson(LessonBlockId $taskId, LessonId $lessonId, int $order): bool
    {
        // Защита от устаревших id: пул заданий мог быть загружен раньше, а само
        // задание — удалено/пересоздано между загрузкой и привязкой. Без этой
        // проверки INSERT в pivot падает по FK и роняет весь сид.
        // withTrashed(): soft-deleted строки физически существуют и FK пройдёт.
        if (!LessonBlockModel::withTrashed()->whereKey($taskId->value)->exists()) {
            return false;
        }

        DB::transaction(function () use ($taskId, $lessonId, $order) {
            // Фаза 1: сдвиг «всё >= order» в зону SAFE_OFFSET.
            $this->shiftFrom($lessonId, $order, BlockOrderPlanner::SAFE_OFFSET);

            // Фаза 2: вставка pivot.
            DB::table('lesson_lesson_block_as_test')->insert([
                'lesson_id'       => $lessonId->value,
                'lesson_block_id' => $taskId->value,
                'order'           => $order,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // Фаза 3: возврат сдвинутых на +1.
            $this->shiftFrom($lessonId, BlockOrderPlanner::SAFE_OFFSET, -1 * (BlockOrderPlanner::SAFE_OFFSET - 1));
        });

        return true;
    }

    public function replaceAttachments(LessonId $lessonId, array $attachments): void
    {
        DB::transaction(function () use ($lessonId, $attachments) {
            DB::table('lesson_lesson_block_as_test')
                ->where('lesson_id', $lessonId->value)
                ->delete();

            if ($attachments === []) {
                return;
            }

            $now  = now();
            $rows = array_map(
                static fn (array $a): array => [
                    'lesson_id'       => $lessonId->value,
                    'lesson_block_id' => (int) $a['id'],
                    'order'           => (int) $a['order'],
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ],
                $attachments,
            );

            DB::table('lesson_lesson_block_as_test')->insert($rows);
        });
    }

    public function placeOwnAt(LessonBlockId $blockId, LessonId $lessonId, int $order): DomainLessonBlock
    {
        DB::transaction(function () use ($blockId, $lessonId, $order) {
            $this->shiftFrom($lessonId, $order, BlockOrderPlanner::SAFE_OFFSET);

            LessonBlockModel::query()
                ->whereKey($blockId->value)
                ->where('lesson_id', $lessonId->value)
                ->update(['order' => $order]);

            $this->shiftFrom(
                $lessonId,
                BlockOrderPlanner::SAFE_OFFSET,
                -1 * (BlockOrderPlanner::SAFE_OFFSET - 1),
            );
        });

        return $this->getById($blockId);
    }

    public function detachFromLesson(LessonBlockId $taskId, LessonId $lessonId): void
    {
        DB::table('lesson_lesson_block_as_test')
            ->where('lesson_id', $lessonId->value)
            ->where('lesson_block_id', $taskId->value)
            ->delete();
    }

    public function delete(LessonBlockId $id): void
    {
        $model = LessonBlockModel::query()->find($id->value);
        $model?->delete();
    }

    public function deleteOwnExcept(LessonId $lessonId, array $keepIds): void
    {
        $query = LessonBlockModel::query()->where('lesson_id', $lessonId->value);

        if ($keepIds !== []) {
            $query->whereNotIn('id', $keepIds);
        }

        foreach ($query->get() as $model) {
            $model->delete();
        }
    }

    public function maxOrderInLesson(LessonId $lessonId): ?int
    {
        $own  = LessonBlockModel::query()->where('lesson_id', $lessonId->value)->max('order');
        $test = DB::table('lesson_lesson_block_as_test')->where('lesson_id', $lessonId->value)->max('order');

        $values = array_filter([$own, $test], static fn ($v): bool => $v !== null);

        return $values === [] ? null : (int) max($values);
    }

    // ===== Настройки / таксономии =====

    public function settingsSchemaForCode(string $blockCode): array
    {
        return BlockSettingsRegistry::serializeSchema($blockCode);
    }

    public function extractPublicDefaults(array $settings, array $schema): array
    {
        return SettingsNormalizer::extractPublicDefaults($settings, $schema);
    }

    public function extractInstanceSettings(array $settings, array $schema): array
    {
        return SettingsNormalizer::extractInstanceSettings($settings, $schema);
    }

    public function syncFileSetsFromSettings(LessonBlockId $blockId, array $settings, string $blockCode): array
    {
        $model = LessonBlockModel::query()->find($blockId->value);

        if ($model === null) {
            return $settings;
        }

        SettingsNormalizer::syncFileSetsFromSettings($settings, $model, $blockCode);
        $model->update(['settings' => $settings]);

        return $settings;
    }

    public function cloneFileSetsFromPrototype(LessonBlockId $blockId, array $settings, int $prototypeBlockId, string $blockCode): array
    {
        $model     = LessonBlockModel::query()->find($blockId->value);
        $prototype = Block::query()->find($prototypeBlockId);

        if ($model === null || $prototype === null) {
            return $settings;
        }

        $settings = SettingsNormalizer::cloneFileSetsFromModel($settings, $model, $prototype, $blockCode);
        $model->update(['settings' => $settings]);

        return $settings;
    }

    public function syncTaskTaxonomies(LessonBlockId $taskId, array $taxonomyIds): void
    {
        $model = LessonBlockModel::query()->find($taskId->value);

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

    public function taskTaxonomies(LessonBlockId $taskId): array
    {
        $model = LessonBlockModel::query()->find($taskId->value);

        if ($model === null) {
            return [];
        }

        return $model->taxonomies()
            ->pluck('taxonomies.id')
            ->map(static fn ($id): TaxonomyId => new TaxonomyId((int) $id))
            ->all();
    }

    public function findBlockPrototype(int $blockId): ?array
    {
        $block = Block::query()->find($blockId);

        if ($block === null) {
            return null;
        }

        return [
            'id'       => (int) $block->id,
            'code'     => (string) $block->code,
            'settings' => is_array($block->settings) ? $block->settings : [],
            'is_test'  => (bool) $block->is_test,
            'is_proc'  => (bool) $block->is_proc,
        ];
    }

    // ===== Dev-/seeding-операции =====

    public function pluckBlockPrototypeIds(?bool $isTest, ?bool $isProc): array
    {
        $query = Block::query();

        if ($isTest !== null) {
            $query->where('is_test', $isTest);
        }
        if ($isProc !== null) {
            $query->where('is_proc', $isProc);
        }

        return $query->orderBy('id')
            ->pluck('id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    public function pluckLeafTaxonomyIds(): array
    {
        return DB::table('taxonomies')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('taxonomies as t_child')
                    ->whereColumn('t_child.parent_id', 'taxonomies.id')
                    ->whereNull('t_child.deleted_at');
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    public function taxonomyHasResolver(int $taxonomyId): bool
    {
        $resolver = DB::table('taxonomies')
            ->where('id', $taxonomyId)
            ->value('test_resolver');

        return !empty($resolver);
    }

    // ===== Агрегаты по задачам =====

    public function pluckAttachedComplexities(LessonId $lessonId): array
    {
        return LessonBlockModel::query()
            ->join('lesson_lesson_block_as_test as p', 'p.lesson_block_id', '=', 'lesson_blocks.id')
            ->where('p.lesson_id', $lessonId->value)
            ->pluck('lesson_blocks.complexity')
            ->map(static fn ($v) => $v === null ? null : (float) $v)
            ->all();
    }

    public function isTaskUsedInLessons(LessonBlockId $taskId): bool
    {
        return DB::table('lesson_lesson_block_as_test')
            ->where('lesson_block_id', $taskId->value)
            ->exists();
    }

    public function pluckUsagesOfTask(LessonBlockId $taskId): array
    {
        return DB::table('lesson_lesson_block_as_test')
            ->where('lesson_block_id', $taskId->value)
            ->pluck('lesson_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * Сдвиг «всё, что >= fromOrder» на delta — по ОБОИМ наборам (own + pivot).
     */
    private function shiftFrom(LessonId $lessonId, int $fromOrder, int $delta): void
    {
        LessonBlockModel::query()
            ->where('lesson_id', $lessonId->value)
            ->where('order', '>=', $fromOrder)
            ->update(['order' => DB::raw('"order" + ' . (int) $delta)]);

        DB::table('lesson_lesson_block_as_test')
            ->where('lesson_id', $lessonId->value)
            ->where('order', '>=', $fromOrder)
            ->update(['order' => DB::raw('"order" + ' . (int) $delta)]);
    }
}
