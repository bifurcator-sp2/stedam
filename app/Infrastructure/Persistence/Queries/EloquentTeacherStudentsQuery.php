<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Queries;

use App\Application\Learn\DTOs\StudentSummary;
use App\Application\Learn\Ports\TeacherStudentsQueryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Learn\Enums\LearnStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Список учеников + агрегаты по learns (count/status/avg score).
 *
 * Агрегаты считаются подзапросами-подсчётами через withCount/withAvg
 * по связи learns, чтобы не тянуть дубликаты join'ом.
 */
final class EloquentTeacherStudentsQuery implements TeacherStudentsQueryInterface
{
    public function paginate(
        array $studentIds,
        ?string $search,
        int $page,
        int $perPage,
    ): PaginatedResult {
        $query = User::query()
            ->whereIn('id', $studentIds)
            ->withCount([
                'learns as learns_count',
                'learns as in_progress_count' => fn (Builder $q) => $q->where('status', LearnStatus::InProgress->value),
                'learns as awaiting_grade_count' => fn (Builder $q) => $q->where('status', LearnStatus::Done->value),
                'learns as graded_count' => fn (Builder $q) => $q->where('status', LearnStatus::Graded->value),
            ])
            ->withAvg(
                ['learns as avg_score' => fn (Builder $q) => $q->where('status', LearnStatus::Graded->value)],
                'score',
            )
            ->orderBy('name')
            ->orderBy('id');

        if ($search !== null && trim($search) !== '') {
            $like = '%'.trim($search).'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'ILIKE', $like)
                    ->orWhere('email', 'ILIKE', $like);
            });
        }

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginator */
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        $items = array_map(
            static fn (User $u): StudentSummary => new StudentSummary(
                id: (int) $u->id,
                name: (string) $u->name,
                email: (string) $u->email,
                learnsCount: (int) ($u->learns_count ?? 0),
                inProgress: (int) ($u->in_progress_count ?? 0),
                awaitingGrade: (int) ($u->awaiting_grade_count ?? 0),
                graded: (int) ($u->graded_count ?? 0),
                avgScore: $u->avg_score !== null ? round((float) $u->avg_score, 2) : null,
            ),
            $paginator->items(),
        );

        return new PaginatedResult(
            items: $items,
            total: $paginator->total(),
            page: $paginator->currentPage(),
            perPage: $paginator->perPage(),
        );
    }
}
