<?php

declare(strict_types=1);

namespace App\Application\Tasks\DTOs;

/**
 * Частичное обновление задания.
 *
 * Соглашение:
 *   — settings: null = «не менять»; [] = «все настройки на default»;
 *   — taxonomyIds: null = «не менять»;
 *   — complexityTouched: false = «не менять»; true + null = «сбросить».
 */
final readonly class UpdateTaskData
{
    /**
     * @param list<int>|null $taxonomyIds
     * @param array<string, mixed>|null $settings
     */
    public function __construct(
        public ?array $settings = null,
        public ?array $taxonomyIds = null,
        public ?float $complexity = null,
        public bool $complexityTouched = false,
    ) {}
}
