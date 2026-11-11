<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mappers;

use App\Domain\LessonBlocks\Entities\LessonBlock as DomainLessonBlock;
use App\Domain\LessonBlocks\ValueObjects\LessonBlockId;
use App\Domain\Lessons\ValueObjects\BlockId;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Models\LessonBlock as LessonBlockModel;

/**
 * Маппер Eloquent LessonBlock ↔ доменная LessonBlock.
 *
 * Для глобальных заданий (lesson_id = null) order в домене = 0:
 * позиция в уроке хранится в pivot, а не в самой строке.
 */
final class LessonBlockMapper
{
    public static function toDomain(LessonBlockModel $model): DomainLessonBlock
    {
        return DomainLessonBlock::fromPersistence(
            id: new LessonBlockId((int) $model->id),
            userId: new UserId((int) $model->user_id),
            blockId: new BlockId((int) $model->block_id),
            lessonId: $model->lesson_id === null ? null : new LessonId((int) $model->lesson_id),
            order: (int) $model->order,
            settings: is_array($model->settings) ? $model->settings : [],
            complexity: Complexity::fromNullable(
                $model->complexity !== null ? (float) $model->complexity : null
            ),
            isProc: (bool) $model->is_proc,
        );
    }

    public static function applyToModel(DomainLessonBlock $domain, LessonBlockModel $model): LessonBlockModel
    {
        $model->user_id    = $domain->userId()->value;
        $model->block_id   = $domain->blockId()->value;
        $model->lesson_id  = $domain->lessonId()?->value;
        $model->order      = $domain->order();
        $model->settings   = $domain->settings();
        $model->complexity = $domain->complexity()?->value;
        $model->is_proc    = $domain->isProc();

        return $model;
    }
}
