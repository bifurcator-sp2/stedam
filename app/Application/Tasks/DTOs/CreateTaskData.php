<?php

declare(strict_types=1);

namespace App\Application\Tasks\DTOs;

/**
 * Создание обычного задания.
 *
 * settings === null или [] — нормально: Application прогонит через
 * SettingsNormalizer::extractPublicDefaults() с настройками прототипа,
 * и все поля получат default-значения.
 *
 * taxonomyIds — минимум 1 (проверяется на уровне домена).
 */
final readonly class CreateTaskData
{
    /**
     * @param list<int> $taxonomyIds
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public int $blockId,
        public array $taxonomyIds,
        public array $settings = [],
        public ?float $complexity = null,
    ) {}
}
