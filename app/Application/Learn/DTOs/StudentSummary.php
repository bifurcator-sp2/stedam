<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

/**
 * Ученик учителя + агрегаты по его прохождениям.
 */
final readonly class StudentSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public int $learnsCount,
        public int $inProgress,
        public int $awaitingGrade,
        public int $graded,
        public ?float $avgScore,
    ) {}
}
