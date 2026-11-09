<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\UserId;

final readonly class StartLearnData
{
    public function __construct(
        public UserId $userId,      // из auth
        public LessonId $lessonId,  // из body
    ) {}
}
