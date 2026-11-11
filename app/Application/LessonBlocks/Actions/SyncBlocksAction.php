<?php

declare(strict_types=1);

namespace App\Application\LessonBlocks\Actions;

use App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface;
use App\Domain\LessonBlocks\Exceptions\BlockAssignmentDenied;
use App\Domain\LessonBlocks\ValueObjects\LessonBlockId;
use App\Domain\Lessons\Ports\LessonRepositoryInterface;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * «Привести состав блоков урока к указанному списку» — syncBlocks.
 *
 * Логика перенесена из LessonController::syncBlocks() один-в-один:
 *
 *   1. Собственные блоки, чьи id есть в списке — обновляются
 *      (settings + order = индекс в списке).
 *   2. Глобальные задания (lesson_id = NULL): settings обновляются,
 *      pivot-строка создаётся заново (порядок = индекс в списке).
 *   3. Собственные блоки, отсутствующие в списке — удаляются.
 *   4. Всё в одной транзакции (транзакцию открывает HTTP-слой).
 *
 * Здесь НЕТ проверки правил урока — она выполняется снаружи (после sync)
 * в LessonRulesChecker через SaveLessonAction.
 */
final readonly class SyncBlocksAction
{
    public function __construct(
        private LessonBlockRepositoryInterface $blocks,
        private LessonRepositoryInterface $lessons,
    ) {}

    /**
     * @param list<array{id:int, settings?:array<string,mixed>|null}> $entries
     */
    public function __invoke(LessonId $lessonId, array $entries, UserId $actor): void
    {
        $own = [];
        foreach ($this->blocks->findOwn($lessonId) as $block) {
            if ($block->id() === null) {
                continue;
            }
            $own[$block->id()->value] = $block;
        }

        $keptOwnIds  = [];
        $attachments = [];

        foreach ($entries as $i => $entry) {
            $id          = isset($entry['id']) ? (int) $entry['id'] : null;
            $newSettings = $entry['settings'] ?? null;

            if ($id === null) {
                throw BlockAssignmentDenied::missingId();
            }

            if (isset($own[$id])) {
                $block = $own[$id];

                if ($newSettings !== null) {
                    $block->replaceSettings(
                        $this->normalizeSettings($block, (array) $newSettings)
                    );
                }

                $block->reorder($i);
                $this->blocks->save($block);

                $keptOwnIds[] = $id;
                continue;
            }

            $sharedId = new LessonBlockId($id);
            $shared   = $this->blocks->findById($sharedId);

            if ($shared === null || !$shared->isGlobal()) {
                throw BlockAssignmentDenied::unknownBlock($id, $lessonId->value);
            }

            if ($newSettings !== null) {
                $shared->replaceSettings(
                    $this->normalizeSettings($shared, (array) $newSettings)
                );
                $this->blocks->save($shared);
            }

            $attachments[] = ['id' => $id, 'order' => $i];
        }

        $this->blocks->deleteOwnExcept($lessonId, $keptOwnIds);
        $this->blocks->replaceAttachments($lessonId, $attachments);
    }

    /**
     * Прогон через file-sets + extractPublicDefaults по схеме прототипа.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function normalizeSettings(\App\Domain\LessonBlocks\Entities\LessonBlock $block, array $settings): array
    {
        $blockId = $block->id();
        if ($blockId === null) {
            return $settings;
        }

        $proto = $this->blocks->findBlockPrototype($block->blockId()->value);
        if ($proto === null) {
            return $settings;
        }

        $synced = $this->blocks->syncFileSetsFromSettings($blockId, $settings, (string) $proto['code']);

        return $this->blocks->extractPublicDefaults($synced, (array) $proto['settings']);
    }
}
