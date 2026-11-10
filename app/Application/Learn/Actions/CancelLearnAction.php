<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\StatusLoggerInterface;
use App\Application\Shared\Clock;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Учитель отменяет запланированное прохождение своего ученика
 * (scheduled → created, дата сбрасывается).
 */
final readonly class CancelLearnAction
{
    public function __construct(
        private LearnRepositoryInterface $learns,
        private ContextResolverInterface $contextResolver,
        private StatusLoggerInterface $statusLogger,
        private Clock $clock,
    ) {}

    public function __invoke(LearnId $learnId, UserId $viewerId): Learn
    {
        $context = $this->contextResolver->resolve($viewerId);

        if (!$context->canCancel) {
            throw new LearnAccessDenied('You are not allowed to cancel');
        }

        $learn = $this->learns->findByIdWithinScope(
            $learnId,
            $context->visibleUserIds(),
        );

        if ($learn === null) {
            throw new LearnNotFound("Learn {$learnId->value} not found");
        }

        if ($learn->userId()->equals($viewerId)) {
            throw new LearnAccessDenied('You cannot cancel your own learn');
        }

        $learn->cancel($this->clock->now());
        $learn = $this->learns->save($learn);

        foreach ($learn->pullEvents() as $event) {
            $this->statusLogger->log(
                learnId: $event->learnId,
                lessonId: $event->lessonId,
                status: $event->status,
                statusDate: $event->statusDate,
                scheduleDate: $event->scheduleDate?->value,
            );
        }

        return $learn;
    }
}
