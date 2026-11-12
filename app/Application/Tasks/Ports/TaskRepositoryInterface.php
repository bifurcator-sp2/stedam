<?php

declare(strict_types=1);

namespace App\Application\Tasks\Ports;

use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Tasks\Entities\Task;
use App\Domain\Tasks\ValueObjects\TaskId;

/**
 * Репозиторий обычных заданий.
 *
 * Отличается от LessonBlockRepositoryInterface тем, что оперирует
 * сущностью Task и её гарантиями (глобальность, таксономии), а не сырым
 * LessonBlock. Реализация может сидеть на той же таблице — это её дело.
 */
interface TaskRepositoryInterface
{
    public function save(Task $task): Task;

    public function getById(TaskId $id): Task;

    public function findById(TaskId $id): ?Task;

    /**
     * Задание используется хоть в одном уроке?
     */
    public function isUsedInLessons(TaskId $id): bool;

    /**
     * Id всех уроков, где используется задание.
     *
     * @return list<int>
     */
    public function pluckUsageLessonIds(TaskId $id): array;

    /**
     * Пересчитать complexity всех уроков, где используется это задание.
     *
     * Возвращает список обновлённых lesson id для логов/ответа.
     *
     * @return list<int>
     */
    public function recalculateComplexityOfUsages(TaskId $id): array;

    /**
     * Удалить задание (мягко/физически — решает инфраструктура).
     */
    public function delete(TaskId $id): void;

    /**
     * Отвязать задание от всех уроков перед удалением.
     * Нужно явно, для того чтобы уроки, где оно использовалось,
     * могли пересчитать complexity.
     *
     * @return list<LessonId>
     */
    public function detachFromAllLessons(TaskId $id): array;

    // ===== Dev-/seeding-операции =====

    /**
     * id-шники всех обычных (is_proc = false) глобальных заданий.
     *
     * @return list<int>
     */
    public function pluckAllIds(): array;

    /**
     * Пул обычных заданий, сгруппированный по таксономии-листу:
     * [taxonomyId => [taskId, ...]]. Включает только задания, у которых
     * есть привязка хотя бы к одной из запрошенных таксономий.
     *
     * @param list<int> $taxonomyIds
     * @return array<int, list<int>>
     */
    public function pluckIdsGroupedByTaxonomy(array $taxonomyIds): array;

    /**
     * Полностью удалить (force) все обычные задания вместе с обрывом
     * привязок в pivot. Используется dev-командой reseed.
     *
     * @return int число удалённых
     */
    public function deleteAll(): int;
}
