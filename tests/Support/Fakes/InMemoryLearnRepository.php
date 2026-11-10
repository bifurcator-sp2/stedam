<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\DTOs\LearnListFilter;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Application\Shared\Pagination\PaginatedResult;
use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Exceptions\LearnNotFound;
use App\Domain\Learn\ValueObjects\LearnId;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\UserId;

final class InMemoryLearnRepository implements LearnRepositoryInterface
{
    /** @var array<int, Learn> */
    private array $items = [];
    private int $nextId = 1;

    public function save(Learn $learn): Learn
    {
        if ($learn->id() === null) {
            $learn = $learn->withId(new LearnId($this->nextId++));
        }
        $this->items[$learn->id()->value] = $learn;

        return $learn;
    }

    public function getById(LearnId $id): Learn
    {
        return $this->items[$id->value]
            ?? throw new LearnNotFound("Learn {$id->value} not found");
    }

    public function findActiveByUserAndLesson(UserId $userId, LessonId $lessonId): ?Learn
    {
        foreach ($this->items as $learn) {
            if ($learn->userId()->equals($userId)
                && $learn->lessonId()->value === $lessonId->value
                && !$learn->status()->isTerminal()
            ) {
                return $learn;
            }
        }

        return null;
    }

    public function findByIdWithinScope(LearnId $id, array $visibleUserIds): ?Learn
    {
        $learn = $this->items[$id->value] ?? null;

        if ($learn === null) {
            return null;
        }

        return in_array($learn->userId()->value, $visibleUserIds, true) ? $learn : null;
    }

    public function listWithinScope(array $visibleUserIds, LearnListFilter $filter): PaginatedResult
    {
        $filtered = array_values(array_filter(
            $this->items,
            static function (Learn $learn) use ($visibleUserIds, $filter): bool {
                if (!in_array($learn->userId()->value, $visibleUserIds, true)) {
                    return false;
                }
                if (!$filter->includeArchived && $learn->isArchived()) {
                    return false;
                }
                if ($filter->statuses !== []
                    && !in_array($learn->status(), $filter->statuses, true)
                ) {
                    return false;
                }
                if ($filter->lessonId !== null
                    && $learn->lessonId()->value !== $filter->lessonId->value
                ) {
                    return false;
                }

                return true;
            },
        ));

        // Новые — первыми (по id desc), как в Eloquent-реализации.
        usort($filtered, static fn (Learn $a, Learn $b): int => $b->id()?->value <=> $a->id()?->value);

        $perPage = max(1, min($filter->perPage, 100));
        $page = max(1, $filter->page);
        $total = count($filtered);
        $items = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        return new PaginatedResult(
            items: $items,
            total: $total,
            page: $page,
            perPage: $perPage,
        );
    }

    public function pluckArchivableIds(
        \App\Domain\Learn\Enums\LearnStatus $status,
        \DateTimeImmutable $cutoff,
        int $limit,
    ): array {
        $ids = [];

        foreach ($this->items as $learn) {
            if (count($ids) >= $limit) {
                break;
            }
            if ($learn->status() !== $status || $learn->isArchived()) {
                continue;
            }

            $reference = $status === \App\Domain\Learn\Enums\LearnStatus::Graded
                ? ($learn->completedAt() ?? $learn->statusDate())
                : $learn->createdAt();

            if ($reference < $cutoff) {
                $ids[] = $learn->id()->value;
            }
        }

        return $ids;
    }
}
