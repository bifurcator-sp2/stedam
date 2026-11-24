<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Enums;

/**
 * Статус агрегата RequestedAction (раздел 1, «status»).
 *
 * Жизненный цикл:
 *   created ──(messaging worker)──▶ pending
 *   pending ──(кворум сошёлся)──▶ approved ──(handle)──▶ done
 *   pending ──(кворум недостижим)──▶ rejected
 *   * ──(ошибка)──▶ error
 *
 * rejected / done / error — терминальные. Переход в error возможен из любого
 * статуса, кроме терминальных.
 */
enum RequestedActionStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Done = 'done';
    case Error = 'error';

    public function isTerminal(): bool
    {
        return $this === self::Rejected
            || $this === self::Done
            || $this === self::Error;
    }

    /**
     * Разрешённые переходы (раздел 2.3):
     *   created → pending
     *   pending → approved | rejected
     *   approved → done
     *   * → error (кроме терминальных)
     * approved → error — только stale-recovery; домен это не различает,
     * политика задаётся Application-слоем.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($target === self::Error) {
            return ! $this->isTerminal();
        }

        return match ($this) {
            self::Created => $target === self::Pending,
            self::Pending => $target === self::Approved || $target === self::Rejected,
            self::Approved => $target === self::Done,
            self::Rejected, self::Done, self::Error => false,
        };
    }
}
