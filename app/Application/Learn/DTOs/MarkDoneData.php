<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Ученик отправляет своё прохождение на проверку.
 *
 * answers — опционально пришедшие «сырые» ответы (фронтенд-структура
 * штрихов: [{version, strokes: [...]}, ...]). Если массив не пуст, он
 * записывается в learns.answers перед сменой статуса; иначе фиксируются
 * уже автосохранённые ответы.
 */
final readonly class MarkDoneData
{
    /**
     * @param list<array<string, mixed>> $answers
     */
    public function __construct(
        public LearnId $learnId,
        public UserId $viewerId,
        public array $answers = [],
    ) {}
}
