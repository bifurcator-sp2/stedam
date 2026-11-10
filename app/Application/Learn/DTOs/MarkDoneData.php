<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Ученик завершает своё прохождение.
 *
 * answers === null — берём уже автосохранённые ответы;
 * answers !== null — перезаписываем финальными (список изображений).
 */
final readonly class MarkDoneData
{
    /**
     * @param list<RawAnswer>|null $answers
     */
    public function __construct(
        public LearnId $learnId,
        public UserId $viewerId,
        public ?array $answers = null,
    ) {}
}
