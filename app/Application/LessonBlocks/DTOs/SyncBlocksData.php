<?php

declare(strict_types=1);

namespace App\Application\LessonBlocks\DTOs;

/**
 * «Привести набор блоков урока к указанному списку».
 *
 * $blocks — упорядоченный список; после успеха:
 *   — для собственных блоков order = индекс в списке;
 *   — для заданий (shared) pivot.order = индекс в списке;
 *   — собственные блоки, отсутствующие в списке, удаляются;
 *   — задания, отсутствующие в списке, отвязываются;
 *   — pivot-строки создаются заново для всех заданий (порядок/дубликаты).
 *
 * @phpstan-type BlockEntry array{id:int, settings?:array<string,mixed>|null}
 */
final readonly class SyncBlocksData
{
    /**
     * @param list<array{id:int, settings?:array<string,mixed>|null}> $blocks
     */
    public function __construct(
        public array $blocks,
    ) {}
}
