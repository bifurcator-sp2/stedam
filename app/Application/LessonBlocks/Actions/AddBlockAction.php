<?php

declare(strict_types=1);

namespace App\Application\LessonBlocks\Actions;

use App\Application\LessonBlocks\DTOs\AddBlockData;
use App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface;
use App\Domain\LessonBlocks\Entities\LessonBlock;
use App\Domain\LessonBlocks\Exceptions\LessonBlockNotFound;
use App\Domain\LessonBlocks\Exceptions\LessonIsPublished;
use App\Application\Lessons\Ports\LessonRepositoryInterface;
use App\Domain\Lessons\Services\BlockOrderPlanner;
use App\Domain\Lessons\ValueObjects\BlockId;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * «Добавить блок к уроку» — POST /api/lessons/{lesson}/blocks.
 *
 * Логика перенесена из LessonController::storeBlock() без изменения
 * наблюдаемого поведения:
 *
 *   1. Права + публикация.
 *   2. Взять прототип Block.
 *      — is_test → создаём ГЛОБАЛЬНОЕ задание (lesson_id = NULL),
 *        привязываем через pivot;
 *      — иначе — обычный блок урока (lesson_id = this.id).
 *   3. Настройки и файлы клонируются с прототипа (порт репозитория
 *      скрывает SettingsNormalizer и file-sets).
 *   4. Порядок сквозной: собственные блоки — lesson_blocks.order,
 *      задания — pivot.order. Сдвиг исполняет инфраструктура по
 *      безопасной схеме SAFE_OFFSET (см. BlockOrderPlanner).
 *   5. Post-save хук репозитория урока пересчитывает complexity.
 */
final readonly class AddBlockAction
{
    public function __construct(
        private LessonRepositoryInterface $lessons,
        private LessonBlockRepositoryInterface $blocks,
    ) {}

    public function __invoke(LessonId $lessonId, UserId $actor, AddBlockData $data): LessonBlock
    {
        $lesson = $this->lessons->getById($lessonId);

        $lesson->assertCanBeEditedBy($actor, $data->isAdmin);

        // Сеялка/dev-утилита может добавлять блоки в опубликованный урок
        // (isAdmin=true и явные settings): публикационный гейт — правило
        // редактора, а не seeding-операции. При обычном HTTP-вызове
        // isAdmin=false и гейт работает как раньше.
        if ($lesson->isPublished() && !$data->isAdmin) {
            throw LessonIsPublished::forLesson($lessonId->value);
        }

        $prototype = $this->blocks->findBlockPrototype($data->blockId);
        if ($prototype === null) {
            throw LessonBlockNotFound::withId($data->blockId);
        }

        $blockId  = new BlockId($prototype['id']);
        $code     = (string) $prototype['code'];
        $settings = $data->settings !== null && $data->settings !== []
            ? $this->blocks->extractInstanceSettings($data->settings, (array) $prototype['settings'])
            : (array) $prototype['settings'];

        if ($prototype['is_test']) {
            return $this->addGlobalTask($lessonId, $actor, $blockId, $code, $settings, (bool) $prototype['is_proc'], $data->order);
        }

        return $this->addOwnBlock($lessonId, $actor, $blockId, $code, $settings, $data->order);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function addGlobalTask(
        LessonId $lessonId,
        UserId $actor,
        BlockId $blockId,
        string $code,
        array $settings,
        bool $isProc,
        ?int $requestedOrder,
    ): LessonBlock {
        $task = LessonBlock::shared(
            userId: $actor,
            blockId: $blockId,
            settings: $settings,
            complexity: null,
            isProc: $isProc,
        );

        $saved  = $this->blocks->save($task);
        $taskId = $saved->id();
        if ($taskId === null) {
            throw new \LogicException('Repository::save did not assign id');
        }

        if ($settings !== []) {
            $saved = $this->syncAndCloneFileSets($saved, $taskId, $code, $settings, $blockId->value);
        }

        $maxOrder = $this->blocks->maxOrderInLesson($lessonId) ?? -1;
        $order    = $requestedOrder ?? ($maxOrder + 1);

        $this->blocks->attachToLesson($taskId, $lessonId, $order);

        // Пересчёт complexity происходит автоматически в модели Lesson:
        // booted()::saving → recalculateComplexity() читает pivot заново.
        // Поэтому достаточно «пустого» save() — он и триггерит хук.
        $this->lessons->save($this->lessons->getById($lessonId));

        return $saved;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function addOwnBlock(
        LessonId $lessonId,
        UserId $actor,
        BlockId $blockId,
        string $code,
        array $settings,
        ?int $requestedOrder,
    ): LessonBlock {
        $lessonBlock = LessonBlock::own(
            userId: $actor,
            blockId: $blockId,
            lessonId: $lessonId,
            order: 0,
            settings: $settings,
        );

        $saved = $this->blocks->save($lessonBlock);
        $ownId = $saved->id();
        if ($ownId === null) {
            throw new \LogicException('Repository::save did not assign id');
        }

        if ($settings !== []) {
            $saved = $this->syncAndCloneFileSets($saved, $ownId, $code, $settings, $blockId->value);
        }

        $maxOrder = $this->blocks->maxOrderInLesson($lessonId) ?? -1;
        $plan     = (new BlockOrderPlanner())->planInsert($requestedOrder, $maxOrder);

        return $this->blocks->placeOwnAt($ownId, $lessonId, $plan->order);
    }

    /**
     * $prototypeBlockId — это id прототипа (blocks.id), не id строки lesson_blocks.
     *
     * @param array<string, mixed> $settings
     */
    private function syncAndCloneFileSets(
        LessonBlock $block,
        \App\Domain\LessonBlocks\ValueObjects\LessonBlockId $blockRowId,
        string $code,
        array $settings,
        int $prototypeBlockId,
    ): LessonBlock {
        $withSynced = $this->blocks->syncFileSetsFromSettings($blockRowId, $settings, $code);
        $cloned     = $this->blocks->cloneFileSetsFromPrototype($blockRowId, $withSynced, $prototypeBlockId, $code);

        $block->replaceSettings($cloned);

        return $this->blocks->save($block);
    }
}
