<?php

declare(strict_types=1);

namespace App\Domain\Lessons\Services;

/**
 * Инструкция «вставить блок на позицию order, сдвинув всё, что >= order».
 *
 * Хранилище обязано выполнить сдвиг безопасно для UNIQUE-индексов
 * (см. BlockOrderPlanner — двухфазный сдвиг).
 */
final readonly class InsertPlan
{
    public function __construct(
        public int $order,
    ) {}
}
