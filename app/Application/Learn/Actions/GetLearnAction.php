<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Чтение одного прохождения с проверкой контекста.
 *
 * Видеть можно только прохождения владельцев из scope смотрящего
 * (ученик — себя, учитель — своих учеников, родитель — детей и т.д.).
 */
final readonly class GetLearnAction
{
    public function __construct(
        private LearnRepositoryInterface $learns,
        private ContextResolverInterface $contextResolver,
    ) {}

    /**
     * @throws LearnNotFound если прохождения нет или оно вне scope
     */
    public function __invoke(LearnId $learnId, UserId $viewerId): Learn
    {
        $context = $this->contextResolver->resolve($viewerId);

        $learn = $this->learns->findByIdWithinScope(
            $learnId,
            $context->visibleUserIds(),
        );

        if ($learn === null) {
            // Вне scope трактуем как «не найдено», чтобы не раскрывать существование.
            throw new LearnNotFound("Learn {$learnId->value} not found");
        }

        return $learn;
    }
}
