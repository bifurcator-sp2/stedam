<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\ValueObjects\Grade;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Учитель оценивает прохождение своего ученика (done → graded).
 */
final readonly class GradeLearnData
{
    public function __construct(
        public LearnId $learnId,
        public UserId $viewerId,
        public Grade $grade,
    ) {}
}
