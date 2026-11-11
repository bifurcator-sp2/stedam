<?php

declare(strict_types=1);

namespace App\Application\TasksProc\DTOs;

/**
 * Создание процедурного задания.
 *
 * Обрати внимание: таксономий нет. Это доменное различие с Tasks,
 * и оно зафиксировано в типе DTO.
 */
final readonly class CreateProcTaskData
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public int $blockId,
        public array $settings = [],
        public ?float $complexity = null,
    ) {}
}
