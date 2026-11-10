<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\SaveAnswersData;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Application\Learn\Ports\AnswerStorageInterface;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\ValueObjects\LearnAnswers;

/**
 * Автосохранение ответов.
 *
 * Только владелец-ученик, только своё прохождение, только in_progress
 * (домен сам проверит editable и бросит InvalidStatusTransition).
 *
 * Изображения-ответы сохраняются в S3 (полная замена набора) до фиксации
 * в домене, чтобы в learns.answers лежали только реально записанные ссылки.
 */
final readonly class SaveAnswersAction
{
    public function __construct(
        private LearnRepositoryInterface $learns,
        private ContextResolverInterface $contextResolver,
        private AnswerStorageInterface $answerStorage,
    ) {}

    public function __invoke(SaveAnswersData $data): Learn
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

        // Своё — значит владелец совпадает со смотрящим.
        if (!$learn->userId()->equals($data->viewerId)) {
            throw new LearnAccessDenied('You can only edit your own learn');
        }

        // 1. Сохраняем изображения (полная замена) → получаем ссылки.
        $images = $this->answerStorage->replaceAll($learn->id(), $data->answers);

        // 2. Фиксируем ответы в домене.
        $learn->updateAnswers(LearnAnswers::fromImages($images));

        return $this->learns->save($learn);
    }
}
