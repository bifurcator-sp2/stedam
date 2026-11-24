<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Сохранение прохождения учеником (своё).
 *
 * answers — фронтенд-структура блоков ответов: [{version, strokes: [...]}, ...].
 * Домен её не интерпретирует, Application передаёт как есть в порт записи.
 */
final readonly class LearnSaveData
{
    /**
     * @param list<array<string, mixed>> $answers
     */
    public function __construct(
        public LearnId $learnId,
        public UserId $viewerId,
        public array $answers,
    ) {}
}
