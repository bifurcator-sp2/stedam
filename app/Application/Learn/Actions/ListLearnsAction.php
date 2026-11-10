<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\DTOs\LearnListFilter;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Постраничный список прохождений в рамках контекста смотрящего.
 *
 * Используется для endpoint'ов вида:
 *   - GET /teacher/learns — прохождения моих учеников;
 *   - собственные прохождения ученика/родителя и т.п.
 *
 * Scope = visibleUserIds контекста; фильтры (статус/урок/даты) — из запроса.
 */
final readonly class ListLearnsAction
{
    public function __construct(
        private LearnRepositoryInterface $learns,
        private ContextResolverInterface $contextResolver,
    ) {}

    /**
     * @return PaginatedResult<Learn>
     */
    public function __invoke(UserId $viewerId, LearnListFilter $filter): PaginatedResult
    {
        $context = $this->contextResolver->resolve($viewerId);

        return $this->learns->listWithinScope(
            $context->visibleUserIds(),
            $filter,
        );
    }
}
