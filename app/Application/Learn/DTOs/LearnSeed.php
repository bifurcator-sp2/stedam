<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Сид генерации заданий прохождения.
 *
 * Детерминирует результат генератора по тройке (userId, learnId, blockIndex):
 * один и тот же seed всегда даёт ту же задачу — это нужно для
 * идемпотентности повторного start() и воспроизводимости (см. спеку §8.2).
 */
final readonly class LearnSeed
{
    public function __construct(
        public UserId $userId,
        public LearnId $learnId,
    ) {}
}
