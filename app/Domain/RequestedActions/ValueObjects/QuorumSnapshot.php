<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\ValueObjects;

use InvalidArgumentException;

/**
 * Проекция голосов для правила раннего решения (раздел 2.4.1).
 *
 * Считается `VotesRepositoryInterface::snapshot()` и передаётся в
 * `handler->registerApproval()`:
 *   accepted  — голосов «принять»
 *   declined  — голосов «отклонить»
 *   remaining — сколько приёмщиков ещё не ответили
 *   quorum    — порог accept-голосов для ACCEPTED (задаёт handler)
 *
 * isSatisfied()  — кворум собран: accepted ≥ quorum.
 * isImpossible() — кворум уже недостижим: declined > total - quorum.
 */
final readonly class QuorumSnapshot
{
    public function __construct(
        public int $accepted,
        public int $declined,
        public int $total,
        public int $quorum,
    ) {
        if ($accepted < 0 || $declined < 0) {
            throw new InvalidArgumentException('Vote counts must be non-negative');
        }

        if ($total < 0) {
            throw new InvalidArgumentException('Total consumers must be non-negative');
        }

        if ($quorum < 1) {
            throw new InvalidArgumentException("Quorum threshold must be >= 1, got {$quorum}");
        }
    }

    public static function from(int $accepted, int $declined, int $total, int $quorum): self
    {
        return new self($accepted, $declined, $total, $quorum);
    }

    public function remaining(): int
    {
        return max(0, $this->total - $this->accepted - $this->declined);
    }

    public function isSatisfied(): bool
    {
        return $this->accepted >= $this->quorum;
    }

    public function isImpossible(): bool
    {
        return $this->declined > $this->total - $this->quorum;
    }
}
