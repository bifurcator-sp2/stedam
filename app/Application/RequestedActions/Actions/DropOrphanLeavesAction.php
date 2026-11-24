<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Infrastructure\RequestedActions\RequestedActionPartitionManager;

/**
 * Добивание «сирот» после сбоев DETACH/DROP (2.8.2).
 *
 * Между `DETACH CONCURRENTLY` и `DROP TABLE` лист может остаться отдельной
 * таблицей без записи в `pg_inherits`. Такие таблицы ломают будущие
 * `PARTITION OF` и мешают повторной нарезке листа — их нужно удалить.
 *
 * Раннер вызывает в начале прогона retention/prune и после него.
 */
final readonly class DropOrphanLeavesAction
{
    public function __construct(
        private RequestedActionPartitionManager $partitions,
    ) {}

    /**
     * @return list<string> имена удалённых сирот
     */
    public function execute(): array
    {
        $dropped = [];

        foreach ($this->partitions->orphanLeaves() as $leaf) {
            $this->partitions->dropOrphan($leaf);
            $dropped[] = $leaf;
        }

        return $dropped;
    }
}
