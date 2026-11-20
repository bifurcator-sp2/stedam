<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Ученик отправляет своё прохождение на проверку.
 *
 * Тело запроса не несёт данных: отправляются уже автосохранённые ответы.
 * Финальная партия изображений приходит отдельно через автосохранение
 * (PATCH /learns/{learn}/answers или PATCH /learn/save).
 */
final readonly class MarkDoneData
{
    public function __construct(
        public LearnId $learnId,
        public UserId $viewerId,
    ) {}
}
