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
 * Учитель запрашивает список своих учеников с агрегатами.
 *
 * «Свои ученики» — из контекста (visibleUserIds), кроме самого смотрящего.
 * Требуется роль teacher (canGrade) — иначе 403.
 */
final readonly class ListTeacherStudentsAction
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

        if (!$context->canGrade && !in_array(ViewerRole::Teacher, $context->roles, true)) {
            throw new LearnAccessDenied('Only teachers can list their students');
        }

        // Свои ученики = видимые, кроме себя.
        $studentIds = array_values(array_filter(
            $context->visibleUserIds(),
            static fn (int $id): bool => $id !== $viewerId->value,
        ));

        return $this->query->paginate($studentIds, $search, $page, $perPage);
    }
}
