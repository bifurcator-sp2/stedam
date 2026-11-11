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
}
