<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mappers;

use App\Domain\Lessons\Entities\Lesson as DomainLesson;
use App\Domain\Lessons\ValueObjects\Complexity;
use App\Domain\Lessons\ValueObjects\LessonId;
use App\Domain\Lessons\ValueObjects\TaxonomyId;
use App\Domain\Lessons\ValueObjects\UserId;
use App\Models\Lesson as LessonModel;

final class LessonMapper
{
    public static function toDomain(LessonModel $model): DomainLesson
    {
        $taxonomyIds = $model->relationLoaded('taxonomies')
            ? $model->taxonomies->pluck('id')->all()
            : $model->taxonomies()->pluck('taxonomies.id')->all();

        return DomainLesson::fromPersistence(
            id: new LessonId((int) $model->id),
            userId: new UserId((int) $model->user_id),
            title: (string) ($model->title ?? ''),
            description: $model->description,
            locale: (string) ($model->locale ?? 'ru'),
            saved: (bool) $model->saved,
            published: (bool) $model->published,
            complexity: Complexity::fromNullable(
                $model->complexity !== null ? (float) $model->complexity : null
            ),
            taxonomyIds: array_map(
                static fn ($id): TaxonomyId => new TaxonomyId((int) $id),
                $taxonomyIds,
            ),
        );
    }

    public static function applyToModel(DomainLesson $domain, LessonModel $model): LessonModel
    {
        $model->user_id     = $domain->userId()->value;
        $model->title       = $domain->title();
        $model->description = $domain->description();
        $model->locale      = $domain->locale();
        $model->saved       = $domain->isSaved();
        $model->published   = $domain->isPublished();
        $model->complexity  = $domain->complexity()?->value;

        return $model;
    }
}
