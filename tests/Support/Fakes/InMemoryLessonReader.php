<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Course\Ports\LessonReaderInterface;
use App\Domain\Course\ValueObjects\LessonId;

/**
 * Чтение уроков в памяти — для тестов Application-слоя курсов.
 *
 * Таксономия задаётся двумя картами: lessonId → taxId и taxId → поддерево.
 * Проверка «курс и уроки в одной ветке» в EditCourseHeaderAction опирается
 * ровно на эти два запроса.
 */
final class InMemoryLessonReader implements LessonReaderInterface
{
    /** @var array<int, float> lessonId => complexity 0..1 */
    private array $complexity = [];

    /** @var array<int, int> lessonId => taxonomyId */
    private array $lessonTaxonomy = [];

    /** @var array<int, list<int>> taxonomyId => все узлы поддерева (включая себя) */
    private array $subtrees = [];

    public function addLesson(int $lessonId, ?float $complexity = null): void
    {
        if ($complexity !== null) {
            $this->complexity[$lessonId] = $complexity;
        } else {
            $this->complexity[$lessonId] = $complexity ?? 0.5;
        }
    }

    public function setTaxonomy(int $lessonId, ?int $taxonomyId): void
    {
        if ($taxonomyId === null) {
            unset($this->lessonTaxonomy[$lessonId]);

            return;
        }

        $this->lessonTaxonomy[$lessonId] = $taxonomyId;
    }

    /**
     * Описать ветку таксономии: $taxonomyId и его потомки.
     *
     * @param list<int> $subtreeIds
     */
    public function setSubtree(int $taxonomyId, array $subtreeIds): void
    {
        $this->subtrees[$taxonomyId] = $subtreeIds;
    }

    public function complexityOf(LessonId $lessonId): ?float
    {
        return $this->complexity[$lessonId->value] ?? null;
    }

    public function complexitiesOf(array $lessonIds): array
    {
        $result = [];

        foreach ($lessonIds as $lessonId) {
            $result[$lessonId->value] = $this->complexityOf($lessonId);
        }

        return $result;
    }

    public function exists(LessonId $lessonId): bool
    {
        return array_key_exists($lessonId->value, $this->complexity);
    }

    public function taxonomyIdOf(LessonId $lessonId): ?int
    {
        return $this->lessonTaxonomy[$lessonId->value] ?? null;
    }

    public function taxonomyIdsOf(array $lessonIds): array
    {
        $result = [];

        foreach ($lessonIds as $lessonId) {
            $result[$lessonId->value] = $this->taxonomyIdOf($lessonId);
        }

        return $result;
    }

    public function taxonomySubtreeIds(int $taxonomyId): array
    {
        return $this->subtrees[$taxonomyId] ?? [$taxonomyId];
    }
}
