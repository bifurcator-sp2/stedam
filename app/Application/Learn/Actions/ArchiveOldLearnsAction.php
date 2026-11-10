<?php

declare(strict_types=1);

namespace App\Application\Learn\Actions;

use App\Application\Learn\DTOs\ArchiveCriteria;
use App\Application\Learn\Ports\LearnRepositoryInterface;
use App\Domain\Learn\Enums\LearnStatus;

/**
 * Результат прогона архивации (для команды/логов).
 */
final readonly class ArchiveResult
{
    /**
     * @param array<string, int> $archivedByStatus
     */
    public function __construct(
        public array $archivedByStatus,
        public bool $dryRun,
    ) {}

    public function total(): int
    {
        return array_sum($this->archivedByStatus);
    }
}

/**
 * Системная архивация устаревших прохождений.
 *
 * Для каждого статуса из критериев:
 *   cutoff = now - N дней;
 *   берём батчами id подходящих прохождений;
 *   загружаем агрегат, archive(), сохраняем.
 *
 * Идемпотентно: домен.archive() не меняет уже архивные; повторный прогон
 * их не трогает (в выборку не попадают — archived_at IS NULL).
 *
 * dry-run: только считает кандидатов, не сохраняет.
 */
final readonly class ArchiveOldLearnsAction
{
    private const BATCH = 500;

    public function __construct(
        private LearnRepositoryInterface $learns,
    ) {}

    public function __invoke(ArchiveCriteria $criteria, bool $dryRun = false): ArchiveResult
    {
        $archivedByStatus = [];

        foreach ($criteria->daysByStatus as $statusValue => $days) {
            $status = LearnStatus::from($statusValue);
            $cutoff = $criteria->now->modify("-{$days} days");

            $count = $this->archiveStatus($status, $cutoff, $criteria->now, $dryRun);
            $archivedByStatus[$statusValue] = $count;
        }

        return new ArchiveResult($archivedByStatus, $dryRun);
    }

    private function archiveStatus(
        LearnStatus $status,
        \DateTimeImmutable $cutoff,
        \DateTimeImmutable $now,
        bool $dryRun,
    ): int {
        $total = 0;

        while (true) {
            $ids = $this->learns->pluckArchivableIds($status, $cutoff, self::BATCH);

            if ($ids === []) {
                break;
            }

            foreach ($ids as $id) {
                if (!$dryRun) {
                    $learn = $this->learns->getById(new \App\Domain\Learn\ValueObjects\LearnId($id));
                    $learn->archive($now);
                    $this->learns->save($learn);
                }
                $total++;
            }

            // В dry-run не меняем БД — иначе зациклимся на тех же id.
            if ($dryRun || count($ids) < self::BATCH) {
                break;
            }
        }

        return $total;
    }
}
