<?php

declare(strict_types=1);

namespace App\Domain\Lessons\Services;

use App\Domain\Lessons\ValueObjects\TaxonomyId;

/**
 * Описание задания, привязанного к уроку — вход для LessonRulesChecker.
 *
 * $blockId — id записи lesson_blocks (не прототипа Block): именно он
 * фигурирует в сообщениях об ошибках в существующей логике.
 */
final readonly class AttachedTask
{
    /**
     * @param list<TaxonomyId> $taxonomyIds
     */
    public function __construct(
        public int $blockId,
        public bool $isProc,
        public array $taxonomyIds,
    ) {}
}
