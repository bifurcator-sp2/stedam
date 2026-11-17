<?php

declare(strict_types=1);

namespace App\Application\Lessons\Actions;

use App\Application\Lessons\DTOs\UpdateLessonData;
use App\Application\Lessons\Exceptions\LessonAccessDeniedError;
use App\Application\Lessons\Ports\LessonRepositoryInterface;
use App\Domain\Lessons\Entities\Lesson;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\LessonType;
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
 * Проверки прав/публикации:
 *   — только владелец может менять (admin-override для save() не
 *     поддерживается);
 *   — опубликованный урок можно только снять с публикации (`published=false`);
 *     попытка поменять что-либо ещё → `LessonIsPublishedEdit` из агрегата
 *     (HTTP 422). Молчаливого no-op здесь нет: иначе клиент не узнаёт,
 *     что изменения не применились.
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

        $taxonomyIds = null;
        if ($data->taxonomyIds !== null) {
            $taxonomyIds = array_map(
                static fn (int $v): TaxonomyId => new TaxonomyId($v),
                $data->taxonomyIds,
            );
        }

        $type = $data->type !== null ? new LessonType($data->type) : null;

        $lesson->update(
            title: $data->title,
            description: $data->description,
            descriptionTouched: $data->descriptionTouched,
            locale: $data->locale,
            type: $type,
            saved: $data->saved,
            published: $data->published,
            taxonomyIds: $taxonomyIds,
        );

        return $this->lessons->save($lesson);
    }
}
