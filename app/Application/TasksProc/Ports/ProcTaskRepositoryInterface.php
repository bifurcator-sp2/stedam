<?php

declare(strict_types=1);

namespace App\Application\TasksProc\Ports;

use App\Domain\TasksProc\Entities\ProcTask;
use App\Domain\TasksProc\ValueObjects\ProcTaskId;

/**
 * Репозиторий процедурных заданий.
 */
interface ProcTaskRepositoryInterface
{
    public function save(ProcTask $task): ProcTask;

    public function getById(ProcTaskId $id): ProcTask;

    public function findById(ProcTaskId $id): ?ProcTask;

    /**
     * Пересчитать complexity всех уроков, где используется это задание.
     *
     * @return list<int>
     */
    public function recalculateComplexityOfUsages(ProcTaskId $id): array;

    // ===== Dev-/seeding-операции =====

    /**
     * id-шники всех процедурных заданий.
     *
     * @return list<int>
     */
    public function pluckAllIds(): array;

    /**
     * Удалить процедурное задание по id вместе с его привязками к урокам.
     * Отсутствие — не ошибка (idempotent delete).
     */
    public function delete(ProcTaskId $id): void;
}
