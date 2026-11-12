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
 *
 * $settings — необязательные готовые настройки блока. Если null/[] —
 * Action возьмёт settings прототипа (extractPublicDefaults). Заданы —
 * используются как есть (с прогоном через file-sets). Нужно сеялкам,
 * которые наполняют блок своим контентом.
 */
final readonly class AddBlockData
{
    /**
     * @param array<string, mixed>|null $settings
     */
    public function __construct(
        public int $blockId,
        public ?int $order = null,
        public bool $isAdmin = false,
        public ?array $settings = null,
    ) {}
}
