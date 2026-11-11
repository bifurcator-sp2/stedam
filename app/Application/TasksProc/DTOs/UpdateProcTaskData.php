<?php

declare(strict_types=1);

namespace App\Application\TasksProc\DTOs;

/**
 * Частичное обновление процедурного задания.
 *
 * Таксономий нет — по определению.
 */
final readonly class UpdateProcTaskData
{
    /**
     * @param array<string, mixed>|null $settings
     */
    public function __construct(
        public ?array $settings = null,
        public ?float $complexity = null,
        public bool $complexityTouched = false,
    ) {}
}
