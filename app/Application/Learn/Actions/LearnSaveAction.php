<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\LearnSaveData;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Application\Learn\Ports\LearnAnswersWriterInterface;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Exceptions\LearnNotFound;

/**
 * Сохранение ответов ученика (штрихи) в своё прохождение.
 *
 * Только владелец: прохождение ищется в scope смотрящего и его
 * user_id обязан совпадать со смотрящим. Физическая запись — через
 * LearnAnswersWriterInterface (Infrastructure), домен структуру
 * штрихов не интерпретирует.
 */
final readonly class LearnSaveAction
{
    public function __construct(
        private LearnRepositoryInterface $learns,
        private ContextResolverInterface $contextResolver,
        private LearnAnswersWriterInterface $answersWriter,
    ) {}

    public function __invoke(LearnSaveData $data): Learn
    {
        $context = $this->contextResolver->resolve($data->viewerId);

        if (!$context->canSave) {
            throw new LearnAccessDenied('You are not allowed to save answers');
        }

        $learn = $this->learns->findByIdWithinScope(
            $data->learnId,
            $context->visibleUserIds(),
        );

        if ($learn === null) {
            throw new LearnNotFound("Learn {$data->learnId->value} not found");
        }

        if (!$learn->userId()->equals($data->viewerId)) {
            throw new LearnAccessDenied('You can only edit your own learn');
        }

        $this->answersWriter->replaceRawAnswers($learn->id(), $data->answers);

        return $learn;
    }
}
