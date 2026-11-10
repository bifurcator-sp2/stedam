<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\Context\ViewerRole;
use App\Application\Learn\Exceptions\LearnAccessDenied;
use App\Application\Learn\Ports\TeacherStudentsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Родитель запрашивает список своих детей с агрегатами по их прохождениям.
 *
 * Read-only. Scope — дети (visibleUserIds минус сам смотрящий).
 * Требуется роль parent — иначе 403.
 */
final readonly class ListParentChildrenAction
{
    public function __construct(
        private TeacherStudentsQueryInterface $query,
        private ContextResolverInterface $contextResolver,
    ) {}

    public function __invoke(
        UserId $viewerId,
        ?string $search,
        int $page,
        int $perPage,
    ): PaginatedResult {
        $context = $this->contextResolver->resolve($viewerId);

        if (!$context->hasRole(ViewerRole::Parent)) {
            throw new LearnAccessDenied('Only parents can list their children');
        }

        $childIds = array_values(array_filter(
            $context->visibleUserIds(),
            static fn (int $id): bool => $id !== $viewerId->value,
        ));

        return $this->query->paginate($childIds, $search, $page, $perPage);
    }
}
