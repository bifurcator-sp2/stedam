<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Concerns\DrainsLearnEvents;
use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\MarkDoneData;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\StatusLoggerInterface;
use App\Application\Shared\Clock;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Exceptions\LearnNotFound;

/**
 * Ученик отправляет своё прохождение на проверку (in_progress → done).
 *
 * Тело запроса не используется: фиксируются уже автосохранённые ответы.
 */
final readonly class MarkDoneAction
{
    use DrainsLearnEvents;

    public function __construct(
        private LearnRepositoryInterface $learns,
        private ContextResolverInterface $contextResolver,
        private StatusLoggerInterface $statusLogger,
        private Clock $clock,
    ) {}

    public function __invoke(MarkDoneData $data): Learn
    {
        $context = $this->contextResolver->resolve($data->viewerId);

        // markDone — часть выполнения учеником (§10.3): в ViewerContext нет
        // отдельного canComplete; право на отправку гейтится через canSave.
        if (!$context->canSave) {
            throw new LearnAccessDenied('You are not allowed to send a learn');
        }

        $learn = $this->learns->findByIdWithinScope(
            $data->learnId,
            $context->visibleUserIds(),
        );

        if ($learn === null) {
            throw new LearnNotFound("Learn {$data->learnId->value} not found");
        }

        if (!$learn->userId()->equals($data->viewerId)) {
            throw new LearnAccessDenied('You can only send your own learn');
        }

        $learn->markDone($learn->answers(), $this->clock->now());
        $learn = $this->learns->save($learn);

        $this->drainLearnEvents($learn);

        return $learn;
    }
}
