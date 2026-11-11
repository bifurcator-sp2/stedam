<?php

declare(strict_types=1);

namespace App\Application\Tasks\Actions;

use App\Application\Tasks\Ports\TaskRepositoryInterface;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Domain\Tasks\Exceptions\TaskAccessDenied;
use App\Domain\Tasks\ValueObjects\TaskId;

/**
 * Удалить обычное задание (TestsController::destroy).
 *
 *   1. Права.
 *   2. Отвязать от уроков (pivot) — репозиторий сам пересчитает
 *      complexity затронутых уроков, если посчитает нужным.
 *   3. Удалить (модель/репозиторий делает soft-delete).
 */
final readonly class DeleteTaskAction
{
    public function __construct(
        private TaskRepositoryInterface $tasks,
    ) {}

    public function __invoke(TaskId $id, UserId $actor, bool $isAdmin): void
    {
        $task = $this->tasks->getById($id);

        if (!$isAdmin && !$task->userId()->equals($actor)) {
            throw TaskAccessDenied::notOwner($id->value, $actor->value);
        }

        $this->tasks->detachFromAllLessons($id);
        $this->tasks->delete($id);
    }
}
