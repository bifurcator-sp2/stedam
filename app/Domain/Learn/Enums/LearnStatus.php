<?php

declare(strict_types=1);

namespace App\Domain\Learn\Enums;

enum LearnStatus: string
{
    case Created = 'created';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Graded = 'graded';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::InProgress, self::Scheduled],
            self::Scheduled => [self::InProgress, self::Scheduled, self::Created],
            self::InProgress => [self::Done],
            self::Done => [self::Graded],
            self::Graded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Graded;
    }

    public function isEditable(): bool
    {
        return $this === self::InProgress;
    }

    public function isOpen(): bool
    {
        return $this === self::Created || $this === self::Scheduled;
    }

    /**
     * Список допустимых значений для CHECK/ENUM в БД.
     * Единственный источник правды о статусах — этот enum;
     * инфраструктура (миграции) читает значения отсюда.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
