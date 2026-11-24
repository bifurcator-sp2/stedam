<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

/**
 * Оценка урока в ответе списка «актуальных уроков» (срез grades).
 *
 * Поля:
 *   — grade  — итоговая оценка, иначе предварительная, иначе null;
 *   — status — человекочитаемое состояние («Оценено» / «Предворительно
 *              оценено» / «Ждет оценки»).
 */
final readonly class GradeSummary
{
    public function __construct(
        public ?float $grade,
        public string $status,
    ) {}

    /**
     * @return array{grade: float|null, status: string}
     */
    public function toArray(): array
    {
        return [
            'grade'  => $this->grade,
            'status' => $this->status,
        ];
    }
}
