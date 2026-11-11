<?php

declare(strict_types=1);

namespace App\Application\Tasks\Actions;

use App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface;
use App\Application\Tasks\DTOs\CreateTaskData;
use App\Application\Tasks\Ports\TaskRepositoryInterface;
use App\Domain\LessonBlocks\ValueObjects\LessonBlockId;
use App\Domain\Lessons\ValueObjects\BlockId;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\TaxonomyId;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Domain\Tasks\Entities\Task;
use App\Domain\Tasks\Exceptions\TaskAccessDenied;
use App\Domain\Tasks\Exceptions\TaskNotFound;
use App\Domain\Tasks\Exceptions\TaxonomyRequired;

/**
 * Создать обычное задание (TestsController::store, is_proc = false).
 */
final readonly class CreateTaskAction
{
    public function __construct(
        private TaskRepositoryInterface $tasks,
        private LessonBlockRepositoryInterface $blocks,
    ) {}

    public function __invoke(UserId $actor, CreateTaskData $data, bool $isAdmin): Task
    {
        $prototype = $this->blocks->findBlockPrototype($data->blockId);
        if ($prototype === null) {
            throw TaskNotFound::withId($data->blockId);
        }

        // Обычное задание нельзя создать из proc-прототипа кому-либо, кроме admin.
        if ($prototype['is_proc'] && !$isAdmin) {
            throw TaskAccessDenied::notOwner(0, $actor->value);
        }

        if ($data->taxonomyIds === []) {
            throw TaxonomyRequired::forTask();
        }

        $taxonomyIds = array_map(
            static fn (int $v): TaxonomyId => new TaxonomyId($v),
            $data->taxonomyIds,
        );

        $settings = $this->blocks->extractPublicDefaults(
            $data->settings,
            (array) $prototype['settings'],
        );

        $task = Task::create(
            userId: $actor,
            blockId: new BlockId($prototype['id']),
            settings: $settings,
            complexity: Complexity::fromNullable($data->complexity),
            taxonomyIds: $taxonomyIds,
        );

        $saved   = $this->tasks->save($task);
        $savedId = $saved->id();
        if ($savedId === null) {
            throw new \LogicException('TaskRepository::save did not assign id');
        }

        if ($settings !== []) {
            $asBlock = new LessonBlockId($savedId->value);
            $withSynced = $this->blocks->syncFileSetsFromSettings($asBlock, $settings, (string) $prototype['code']);

            $saved->replaceSettings($withSynced);
            $saved = $this->tasks->save($saved);
        }

        $this->blocks->syncTaskTaxonomies(new LessonBlockId($savedId->value), $taxonomyIds);

        return $saved;
    }
}
