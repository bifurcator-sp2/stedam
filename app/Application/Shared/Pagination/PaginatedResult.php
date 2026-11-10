<?php

declare(strict_types=1);

namespace App\Application\Shared\Pagination;

/**
 * Результат постраничного чтения — Application-понятие.
 *
 * Не зависит от Eloquent-пагинатора: инфраструктура отдаёт его,
 * HTTP-слой маппит в привычный JSON (data + meta).
 *
 * @template T
 */
final readonly class PaginatedResult
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function lastPage(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 1;
    }

    public function hasMorePages(): bool
    {
        return $this->page < $this->lastPage();
    }
}
