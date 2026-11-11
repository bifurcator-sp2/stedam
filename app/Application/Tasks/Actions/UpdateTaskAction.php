<?php

declare(strict_types=1);

namespace App\Application\Tasks\Actions;

use App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface;
use App\Application\Tasks\DTOs\UpdateTaskData;
use App\Application\Tasks\Ports\TaskRepositoryInterface;
use App\Domain\LessonBlocks\ValueObjects\LessonBlockId;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\TaxonomyId;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Domain\Tasks\Entities\Task;
use App\Domain\Tasks\Exceptions\TaskAccessDenied;
use App\Domain\Tasks\ValueObjects\TaskId;

/**
 * Обновить обычное задание (TestsController::update, is_proc = false).
 */
final readonly class UpdateTaskAction
{
    public function __construct(
        private TaskRepositoryInterface $tasks,
        private LessonBlockRepositoryInterface $blocks,
    ) {}

    public function __invoke(TaskId $id, UserId $actor, UpdateTaskData $data, bool $isAdmin): Task
    {
        $task = $this->tasks->getById($id);

        if (!$isAdmin && !$task->userId()->equals($actor)) {
            throw TaskAccessDenied::notOwner($id->value, $actor->value);
        }

        $isUsed = $this->tasks->isUsedInLessons($id);

        $settings = $data->settings;
        if ($settings === null) {
            $settings = $task->settings();
        } else {
            $proto  = $this->blocks->findBlockPrototype($task->blockId()->value);
            $schema = (array) ($proto['settings'] ?? []);
            $settings = $this->blocks->extractPublicDefaults($settings, $schema);
        }

        $taxonomyIds = null;
        if ($data->taxonomyIds !== null) {
            $taxonomyIds = array_map(
                static fn (int $v): TaxonomyId => new TaxonomyId($v),
                $data->taxonomyIds,
            );
        }

        $complexityChanged = $task->update(
            settings: $settings,
            complexity: Complexity::fromNullable($data->complexity),
            complexityTouched: $data->complexityTouched,
            taxonomyIds: $taxonomyIds,
            taxonomyTouched: $data->taxonomyIds !== null,
            isUsedInLessons: $isUsed,
        );

        $saved   = $this->tasks->save($task);
        $savedId = $saved->id();
        if ($savedId === null) {
            throw new \LogicException('TaskRepository::save did not assign id');
        }

        if ($data->settings !== null && $settings !== []) {
            $proto = $this->blocks->findBlockPrototype($saved->blockId()->value);
            if ($proto !== null) {
                $asBlock    = new LessonBlockId($savedId->value);
                $withSynced = $this->blocks->syncFileSetsFromSettings($asBlock, $settings, (string) $proto['code']);

                $saved->replaceSettings($withSynced);
                $saved = $this->tasks->save($saved);
            }
        }

        if ($taxonomyIds !== null) {
            $this->blocks->syncTaskTaxonomies(new LessonBlockId($savedId->value), $taxonomyIds);
        }

        if ($complexityChanged) {
            $this->tasks->recalculateComplexityOfUsages($id);
        }

        return $saved;
    }
}
