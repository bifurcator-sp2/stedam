<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\Context\ViewerRole;
use App\Application\Learn\DTOs\LearnListFilter;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Прохождения детей текущего родителя (read-only).
 *
 * Scope — только дети (visibleUserIds минус сам смотрящий),
 * чтобы родитель не видел собственные прохождения в этом списке.
 * Требуется роль parent — иначе 403.
 */
final readonly class ListParentLearnsAction
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

        if (!$context->hasRole(ViewerRole::Parent)) {
            throw new LearnAccessDenied('Only parents can list their children learns');
        }

        $childIds = array_values(array_filter(
            $context->visibleUserIds(),
            static fn (int $id): bool => $id !== $viewerId->value,
        ));

        return $this->learns->listWithinScope($childIds, $filter);
    }
}
