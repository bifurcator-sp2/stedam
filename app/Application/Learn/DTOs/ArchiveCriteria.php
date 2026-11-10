<?php

declare(strict_types=1);

namespace App\Application\Learn\DTOs;

use App\Domain\Learn\Enums\LearnStatus;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Критерии системной архивации: какой статус и через сколько дней после
 * «опорной» даты отправлять в архив.
 *
 * Опорная дата зависит от статуса (см. Learn::archiveEligible...):
 *   graded    → complete_date (fallback status_date)
 *   created   → created_at
 *   scheduled → created_at
 *   done/in_progress — не архивируются (нет в карте).
 */
final readonly class ArchiveCriteria
{
    /**
     * @param array<string, int> $daysByStatus статус(value) => порог в днях
     */
    public function __construct(
        public array $daysByStatus,
        public DateTimeImmutable $now,
    ) {
        foreach ($daysByStatus as $status => $days) {
            if (LearnStatus::tryFrom($status) === null) {
                throw new InvalidArgumentException("Unknown status in archive criteria: {$status}");
            }
            if ($days < 0) {
                throw new InvalidArgumentException("Archive days must be >= 0 for {$status}");
            }
        }
    }

    /**
     * Собирает критерии из конфига config('learn.archive').
     *
     * @param array{after_days?: array<string, int>} $config
     */
    public static function fromConfig(array $config, DateTimeImmutable $now): self
    {
        /** @var array<string, int> $days */
        $days = $config['after_days'] ?? [];

        return new self($days, $now);
    }

    /**
     * Разовый override из CLI: ограничить набором статусов и/или порогом дней.
     *
     * @param list<LearnStatus> $statuses только эти статусы (пусто — все из карты)
     */
    public function withOverrides(array $statuses, ?int $days): self
    {
        $map = $this->daysByStatus;

        if ($statuses !== []) {
            $allowed = array_fill_keys(
                array_map(static fn (LearnStatus $s): string => $s->value, $statuses),
                true,
            );
            $map = array_filter(
                $map,
                static fn (string $status): bool => isset($allowed[$status]),
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($days !== null) {
            $map = array_map(static fn (): int => $days, $map);
        }

        return new self($map, $this->now);
    }

    /**
     * @return list<LearnStatus>
     */
    public function statuses(): array
    {
        return array_values(array_filter(
            array_map(
                static fn (string $s): ?LearnStatus => LearnStatus::tryFrom($s),
                array_keys($this->daysByStatus),
            ),
        ));
    }

    public function isEmpty(): bool
    {
        return $this->daysByStatus === [];
    }
}
