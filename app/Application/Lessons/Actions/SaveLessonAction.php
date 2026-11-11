<?php

declare(strict_types=1);

namespace App\Application\Lessons\Actions;

use App\Application\LessonBlocks\Actions\SyncBlocksAction;
use App\Application\Lessons\DTOs\UpdateLessonData;
use App\Domain\Lessons\Entities\Lesson;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * Полный «PUT /api/lessons/{id}»:
 *   1. Права + публикация  — UpdateLessonAction.
 *   2. Шапка/таксономии    — UpdateLessonAction.
 *   3. Синхронизация блоков — SyncBlocksAction.
 *   4. Правила урока        — LessonRulesChecker (вызывается из HTTP-слоя
 *                             ПОСЛЕ этого действия, когда состав известен).
 *
 * Транзакция объявляется в HTTP-контроллере: там же — validateLessonRules.
 * Этот Action сам транзакцию не открывает, чтобы вызывающая сторона
 * могла объединить шаги атомарно.
 */
final readonly class SaveLessonAction
{
    public function __construct(
        private UpdateLessonAction $update,
        private SyncBlocksAction $syncBlocks,
    ) {}

    public function __invoke(LessonId $id, UserId $userId, UpdateLessonData $data): Lesson
    {
        $lesson = ($this->update)($id, $userId, $data);

        // Опубликованный урок и не просят снять публикацию —
        // Update вернул «как есть», блоки не трогаем.
        if ($lesson->isPublished() && $data->published !== false) {
            return $lesson;
        }

        if ($data->blocks !== null) {
            ($this->syncBlocks)($id, $data->blocks, $userId);
        }

        return $lesson;
    }
}
