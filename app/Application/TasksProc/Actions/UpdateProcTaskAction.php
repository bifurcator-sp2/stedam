<?php

declare(strict_types=1);

namespace App\Application\TasksProc\Actions;

use App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface;
use App\Application\TasksProc\DTOs\UpdateProcTaskData;
use App\Application\TasksProc\Ports\ProcTaskRepositoryInterface;
use App\Domain\LessonBlocks\ValueObjects\LessonBlockId;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Domain\TasksProc\Entities\ProcTask;
use App\Domain\TasksProc\ValueObjects\ProcTaskId;

/**
 * Обновить процедурное задание (TestsController::update, is_proc = true).
 */
final readonly class UpdateProcTaskAction
{
    public function __construct(
        private ProcTaskRepositoryInterface $procTasks,
        private LessonBlockRepositoryInterface $blocks,
    ) {}

    public function __invoke(ProcTaskId $id, UserId $actor, UpdateProcTaskData $data, bool $isAdmin): ProcTask
    {
        $task = $this->procTasks->getById($id);

        $settings = $data->settings;
        if ($settings === null) {
            $settings = $task->settings();
        } else {
            $proto  = $this->blocks->findBlockPrototype($task->blockId()->value);
            $schema = (array) ($proto['settings'] ?? []);
            $settings = $this->blocks->extractPublicDefaults($settings, $schema);
        }

        $complexityChanged = $task->update(
            settings: $settings,
            complexity: Complexity::fromNullable($data->complexity),
            complexityTouched: $data->complexityTouched,
            isAdmin: $isAdmin,
            actor: $actor,
        );

        $saved   = $this->procTasks->save($task);
        $savedId = $saved->id();
        if ($savedId === null) {
            throw new \LogicException('ProcTaskRepository::save did not assign id');
        }

        if ($data->settings !== null && $settings !== []) {
            $proto = $this->blocks->findBlockPrototype($saved->blockId()->value);
            if ($proto !== null) {
                $asBlock    = new LessonBlockId($savedId->value);
                $withSynced = $this->blocks->syncFileSetsFromSettings($asBlock, $settings, (string) $proto['code']);

                $saved->replaceSettings($withSynced);
                $saved = $this->procTasks->save($saved);
            }
        }

        if ($complexityChanged) {
            $this->procTasks->recalculateComplexityOfUsages($id);
        }

        return $saved;
    }
}
