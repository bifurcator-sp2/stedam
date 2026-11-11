<?php

declare(strict_types=1);

namespace App\Application\LessonBlocks\DTOs;

/**
 * «Добавить блок к уроку».
 *
 * $blockId — id прототипа (blocks), из которого создаётся LessonBlock.
 * $order === null — вставить в конец. $order задан — на конкретную позицию.
 *
 * $isAdmin — admin-override: позволяет добавлять блоки в чужой урок.
 */
final readonly class AddBlockData
{
    public function __construct(
        public int $blockId,
        public ?int $order = null,
        public bool $isAdmin = false,
    ) {}
}
