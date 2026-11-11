<?php

declare(strict_types=1);

namespace App\Application\Lessons\Actions;

use App\Application\Lessons\DTOs\UpdateLessonData;
use App\Application\Lessons\Exceptions\LessonAccessDeniedError;
use App\Application\Lessons\Ports\LessonRepositoryInterface;
use App\Domain\Lessons\Entities\Lesson;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\TaxonomyId;
use App\Domain\Lessons\ValueObjects\UserId;

/**
 * Обновить шапку и/или таксономии урока.
 *
 * Что НЕ входит:
 *   — syncBlocks (см. LessonBlocks\SyncBlocksAction);
 *   — правила урока (см. LessonRulesChecker, применение — тут же);
 *   — пересчёт complexity (см. пересчёт в LessonBlocks actions).
 *
 * Проверки прав:
 *   — если урок опубликован и запрос не снимает публикацию →
 *     «no-op»: возвращаем урок как есть (соответствует текущему
 *     контроллеру: при редактировании опубликованного без снятия
 *     публикации отдаётся ресурс без изменений).
 *   — иначе: только владелец может менять (admin-override не
 *     поддерживался в контроллере для save()).
 */
final readonly class UpdateLessonAction
{
    public function __construct(
        private LessonRepositoryInterface $lessons,
    ) {}

    public function __invoke(LessonId $id, UserId $userId, UpdateLessonData $data): Lesson
    {
        $lesson = $this->lessons->getById($id);

        if (!$lesson->userId()->equals($userId)) {
            throw LessonAccessDeniedError::notOwner($id->value, $userId->value);
        }

        // Опубликованный урок редактируется только в режиме «снять с публикации».
        $unpublishRequested = $data->published === false;
        if ($lesson->isPublished() && !$unpublishRequested) {
            return $lesson;
        }

        $taxonomyIds = null;
        if ($data->taxonomyIds !== null) {
            $taxonomyIds = array_map(
                static fn (int $v): TaxonomyId => new TaxonomyId($v),
                $data->taxonomyIds,
            );
        }

        $lesson->update(
            title: $data->title,
            description: $data->description,
            descriptionTouched: $data->descriptionTouched,
            locale: $data->locale,
            saved: $data->saved,
            published: $data->published,
            taxonomyIds: $taxonomyIds,
        );

        return $this->lessons->save($lesson);
    }
}
