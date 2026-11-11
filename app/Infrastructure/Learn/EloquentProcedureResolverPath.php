<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\Ports\ProcedureResolverPathInterface;
use App\Domain\Blocks\TestGenerator\Resolvers\TestResolverMap;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Models\Lesson;
use App\Models\Taxonomy;

/**
 * Поиск resolver-пути генератора процедурных заданий.
 *
 * Единственная реализация алгоритма «подняться по parent_id до ближайшего
 * непустого test_resolver и проверить его по реестру генераторов». Ранее
 * этот код был продублирован в SettingsNormalizer::fillProcTests и
 * в контроллерах задач.
 */
final class EloquentProcedureResolverPath implements ProcedureResolverPathInterface
{
    /** Защита от циклов в дереве таксономий. */
    private const MAX_DEPTH = 64;

    public function __construct(
        private readonly TestResolverMap $resolverMap,
    ) {}

    public function resolveForLesson(LessonId $lessonId): ?string
    {
        $lesson = Lesson::query()->find($lessonId->value);
        if ($lesson === null) {
            return null;
        }

        $taxonomyId = $lesson->relationLoaded('taxonomies')
            ? $lesson->taxonomies->first()?->id
            : $lesson->taxonomies()->value('taxonomies.id');

        return $taxonomyId ? $this->resolveForTaxonomy((int) $taxonomyId) : null;
    }

    public function resolveForTaxonomy(int $taxonomyId): ?string
    {
        $node  = Taxonomy::query()->find($taxonomyId);
        $guard = 0;

        while ($node !== null && $guard++ < self::MAX_DEPTH) {
            $resolver = is_string($node->test_resolver) ? trim($node->test_resolver) : '';

            if ($resolver !== '') {
                $matched = $this->resolverMap->matchedPath($resolver);
                if ($matched !== null) {
                    return $matched;
                }
            }

            if ($node->parent_id === null || $node->parent_id < 0) {
                break;
            }

            $node = Taxonomy::query()->find($node->parent_id);
        }

        return null;
    }
}
