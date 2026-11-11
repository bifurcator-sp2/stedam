<?php

declare(strict_types=1);

namespace App\Application\Lessons\DTOs;

/**
 * Частичное обновление урока.
 *
 * Отличие null от «не задано»:
 *   — title / locale / published: null = «не менять»;
 *   — description: явно поле presence через $descriptionTouched
 *     (в API его можно обнулить);
 *   — taxonomyIds: null = «не менять».
 *
 * blocks — поток блоков для sync (LessonBlocks::SyncBlocksAction):
 *   каждое звено — id существующего блока + опциональные settings.
 *
 * @phpstan-type BlockEntry array{id:int, settings?:array<string,mixed>|null}
 */
final readonly class UpdateLessonData
{
    /**
     * @param list<int>|null $taxonomyIds
     * @param list<array{id:int, settings?:array<string,mixed>|null}>|null $blocks
     */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public bool $descriptionTouched = false,
        public ?string $locale = null,
        public ?bool $saved = null,
        public ?bool $published = null,
        public ?array $taxonomyIds = null,
        public ?array $blocks = null,
    ) {}
}
