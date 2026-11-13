<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Concerns\DrainsLearnEvents;
use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\GradeLearnData;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\StatusLoggerInterface;
use App\Application\Shared\Clock;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Exceptions\LearnNotFound;

/**
 * Учитель оценивает прохождение своего ученика (done → graded).
 *
 * graded_by = viewerId (ручная оценка). Автоматическая оценка системой
 * (graded_by = null) — отдельный механизм, здесь не реализуется.
 */
final readonly class GradeLearnAction
{
    use DrainsLearnEvents;

    public function __construct(
        private LearnRepositoryInterface $learns,
        private ContextResolverInterface $contextResolver,
        private StatusLoggerInterface $statusLogger,
        private Clock $clock,
    ) {}

    public function __invoke(GradeLearnData $data): Learn
    {
        $context = $this->contextResolver->resolve($data->viewerId);

        if (!$context->canGrade) {
            throw new LearnAccessDenied('You are not allowed to grade');
        }

        $learn = $this->learns->findByIdWithinScope(
            $data->learnId,
            $context->visibleUserIds(),
        );

        if ($learn === null) {
            throw new LearnNotFound("Learn {$data->learnId->value} not found");
        }

        // Нельзя оценивать себя (учитель-ученик мультироль и т.п.).
        if ($learn->userId()->equals($data->viewerId)) {
            throw new LearnAccessDenied('You cannot grade your own learn');
        }

        $learn->markGraded($data->grade, $data->viewerId, $this->clock->now());
        $learn = $this->learns->save($learn);

        $this->drainLearnEvents($learn);

        return $learn;
    }
}
