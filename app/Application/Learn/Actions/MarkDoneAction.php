<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\MarkDoneData;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Application\Learn\Ports\AnswerStorageInterface;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Learn\Ports\StatusLoggerInterface;
use App\Application\Shared\Clock;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\ValueObjects\LearnAnswers;

/**
 * Ученик завершает своё прохождение (in_progress → done).
 *
 * Ответы: если переданы в теле — перезаписывают сохранённые, иначе
 * фиксируются текущие автосохранённые.
 */
final readonly class MarkDoneAction
{
    public function __construct(
        private LearnRepositoryInterface $learns,
        private ContextResolverInterface $contextResolver,
        private StatusLoggerInterface $statusLogger,
        private AnswerStorageInterface $answerStorage,
        private Clock $clock,
    ) {}

    public function __invoke(MarkDoneData $data): Learn
    {
        $context = $this->contextResolver->resolve($data->viewerId);

        // markDone — часть выполнения учеником (§10.3): в ViewerContext нет
        // отдельного canComplete; право на завершение гейтится через canSave.
        if (!$context->canSave) {
            throw new LearnAccessDenied('You are not allowed to complete a learn');
        }

        $learn = $this->learns->findByIdWithinScope(
            $data->learnId,
            $context->visibleUserIds(),
        );

        if ($learn === null) {
            throw new LearnNotFound("Learn {$data->learnId->value} not found");
        }

        if (!$learn->userId()->equals($data->viewerId)) {
            throw new LearnAccessDenied('You can only complete your own learn');
        }

        $answers = $data->answers !== null
            ? LearnAnswers::fromImages($this->answerStorage->replaceAll($learn->id(), $data->answers))
            : $learn->answers();

        $learn->markDone($answers, $this->clock->now());
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
