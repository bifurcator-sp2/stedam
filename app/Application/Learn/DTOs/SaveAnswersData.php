<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Автосохранение ответов учеником (своё прохождение, in_progress).
 *
 * answers — список изображений-ответов (позиция блока + загруженный файл).
 */
final readonly class SaveAnswersData
{
    /**
     * @param list<RawAnswer> $answers
     */
    public function __construct(
        public LearnId $learnId,
        public UserId $viewerId,
        public array $answers,
    ) {}
}
