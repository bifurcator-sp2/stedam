<?php

declare(strict_types=1);

namespace App\Application\TasksProc\Actions;

use App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface;
use App\Application\TasksProc\DTOs\CreateProcTaskData;
use App\Application\TasksProc\Ports\ProcTaskRepositoryInterface;
use App\Domain\LessonBlocks\ValueObjects\LessonBlockId;
use App\Domain\Lessons\ValueObjects\BlockId;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Domain\TasksProc\Entities\ProcTask;
use App\Domain\TasksProc\Exceptions\ProcTaskAccessDenied;
use App\Domain\TasksProc\Exceptions\ProcTaskNotFound;

/**
 * Создать процедурное задание (TestsController::store, is_proc = true).
 *
 * Отличия от обычного задания:
 *   — таксономий нет;
 *   — может создавать только admin.
 */
final readonly class CreateProcTaskAction
{
    public function __construct(
        private ProcTaskRepositoryInterface $procTasks,
        private LessonBlockRepositoryInterface $blocks,
    ) {}

    public function __invoke(UserId $actor, CreateProcTaskData $data, bool $isAdmin): ProcTask
    {
        if (!$isAdmin) {
            throw ProcTaskAccessDenied::mutation($actor->value);
        }

        $prototype = $this->blocks->findBlockPrototype($data->blockId);
        if ($prototype === null) {
            throw ProcTaskNotFound::withId($data->blockId);
        }

        if (empty($prototype['is_proc'])) {
            throw new \DomainException(
                "Block prototype #{$prototype['id']} (code={$prototype['code']}) is not proc"
            );
        }

        $settings = $this->blocks->extractPublicDefaults(
            $data->settings,
            (array) $prototype['settings'],
        );

        $procTask = ProcTask::create(
            userId: $actor,
            blockId: new BlockId($prototype['id']),
            settings: $settings,
            complexity: Complexity::fromNullable($data->complexity),
            isAdmin: $isAdmin,
        );

        $saved   = $this->procTasks->save($procTask);
        $savedId = $saved->id();
        if ($savedId === null) {
            throw new \LogicException('ProcTaskRepository::save did not assign id');
        }

        if ($settings !== []) {
            $asBlock    = new LessonBlockId($savedId->value);
            $withSynced = $this->blocks->syncFileSetsFromSettings($asBlock, $settings, (string) $prototype['code']);

            $saved->replaceSettings($withSynced);
            $saved = $this->procTasks->save($saved);
        }

        return $saved;
    }
}
