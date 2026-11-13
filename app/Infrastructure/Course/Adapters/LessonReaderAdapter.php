<?php

declare(strict_types=1);

namespace App\Infrastructure\Course\Adapters;

use App\Application\Course\Ports\LessonReaderInterface;
use App\Domain\Course\ValueObjects\LessonId as CourseLessonId;
use App\Models\Lesson;
use App\Models\LessonTaxonomy;
use App\Models\Taxonomy;

/**
 * Адаптер чтения уроков из контекста Lessons.
 *
 * Только чтение и только то, что нужно курсам: существование, сложность,
 * ветка таксономии.
 *
 * Сложность урока хранится в БД уже в 0..1 (lessons.complexity —
 * double(0..1)), поэтому нормализации нет — в отличие от Learn\Grade (0..100).
 */
final readonly class LessonReaderAdapter implements LessonReaderInterface
{
    /**
     * В таксономии корень — parent_id = -1 (см. миграцию taxonomies).
     */
    private const int TAXONOMY_ROOT_PARENT_ID = -1;

    public function complexityOf(CourseLessonId $lessonId): ?float
    {
        $value = Lesson::query()
            ->whereKey($lessonId->value)
            ->value('complexity');

        return $value === null ? null : (float) $value;
    }

    public function complexitiesOf(array $lessonIds): array
    {
        $ids = array_map(static fn (CourseLessonId $id): int => $id->value, $lessonIds);

        if ($ids === []) {
            return [];
        }

        $rows = Lesson::query()
            ->whereIn('id', $ids)
            ->pluck('complexity', 'id');

        $result = array_fill_keys($ids, null);

        foreach ($rows as $id => $complexity) {
            $result[(int) $id] = $complexity === null ? null : (float) $complexity;
        }

        return $result;
    }

    public function exists(CourseLessonId $lessonId): bool
    {
        return Lesson::query()->whereKey($lessonId->value)->exists();
    }

    public function taxonomyIdOf(CourseLessonId $lessonId): ?int
    {
        $taxId = LessonTaxonomy::query()
            ->where('lesson_id', $lessonId->value)
            ->value('taxonomy_id');

        return $taxId === null ? null : (int) $taxId;
    }

    public function taxonomyIdsOf(array $lessonIds): array
    {
        $ids = array_map(static fn (CourseLessonId $id): int => $id->value, $lessonIds);

        if ($ids === []) {
            return [];
        }

        $rows = LessonTaxonomy::query()
            ->whereIn('lesson_id', $ids)
            ->get(['lesson_id', 'taxonomy_id']);

        $result = array_fill_keys($ids, null);

        foreach ($rows as $row) {
            $result[(int) $row->lesson_id] = (int) $row->taxonomy_id;
        }

        return $result;
    }

    /**
     * Узел + все потомки.
     *
     * Дерево обходим в памяти: таксономия — это справочник (сотни строк,
     * не миллионы), рекурсивный CTE здесь дороже, чем один SELECT.
     *
     * @return list<int>
     */
    public function taxonomySubtreeIds(int $taxonomyId): array
    {
        $all = Taxonomy::query()->get(['id', 'parent_id']);

        $childrenByParent = [];
        foreach ($all as $row) {
            $parentId = (int) ($row->parent_id ?? self::TAXONOMY_ROOT_PARENT_ID);
            $childrenByParent[$parentId][] = (int) $row->id;
        }

        $result = [];
        $seen = [];
        $stack = [$taxonomyId];

        while ($stack !== []) {
            $current = (int) array_pop($stack);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $result[] = $current;

            foreach ($childrenByParent[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $result;
    }
}
