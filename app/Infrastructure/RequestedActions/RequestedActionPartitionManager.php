<?php

declare(strict_types=1);

namespace App\Infrastructure\RequestedActions;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;

/**
 * Управление помесячными листьями дерева `requested_actions` (2.8).
 *
 * Дерево:
 *   requested_actions            (LIST complete)
 *     requested_actions_active     (RANGE created_at)
 *     requested_actions_complete   (RANGE created_at)
 *
 * Лист активной ветки несёт UNIQUE (user_id, handler, payload_hash) —
 * без него не работает INSERT ... ON CONFLICT DO NOTHING (2.8.1).
 *
 * Все операции идемпотентны (`IF NOT EXISTS`), поэтому вызываются и из
 * EnsureRequestedActionPartitionAction перед INSERT, и из retention.
 */
final readonly class RequestedActionPartitionManager
{
    public const ACTIVE = 'active';

    public const COMPLETE = 'complete';

    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function ensureActiveFor(DateTimeImmutable $moment): string
    {
        return $this->ensure(self::ACTIVE, $moment);
    }

    public function ensureCompleteFor(DateTimeImmutable $moment): string
    {
        return $this->ensure(self::COMPLETE, $moment);
    }

    /**
     * Имена помесячных листьев ветки.
     *
     * @return list<string>
     */
    public function leaves(string $branch): array
    {
        $rows = $this->db->connection()->select(
            "SELECT c.relname
               FROM pg_class c
               JOIN pg_inherits i ON i.inhrelid = c.oid
               JOIN pg_class p ON p.oid = i.inhparent
              WHERE p.relname = ?
                AND c.relname ~ '^requested_actions_(active|complete)_[0-9]{4}_[0-9]{2}$'
              ORDER BY c.relname",
            [sprintf('requested_actions_%s', $branch)],
        );

        return array_map(static fn (object $row): string => (string) $row->relname, $rows);
    }

    /**
     * Таблицы с именем листа, но без связи с родителем (незавершённый
     * DETACH или уже отцепленный лист). 2.8.2, recovery-фаза.
     *
     * @return list<string>
     */
    public function orphanLeaves(): array
    {
        $rows = $this->db->connection()->select(
            "SELECT c.relname
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = current_schema()
                AND c.relkind = 'r'
                AND c.relname ~ '^requested_actions_(active|complete)_[0-9]{4}_[0-9]{2}$'
                AND NOT EXISTS (
                      SELECT 1 FROM pg_inherits i WHERE i.inhrelid = c.oid
                )
              ORDER BY c.relname",
        );

        return array_map(static fn (object $row): string => (string) $row->relname, $rows);
    }

    /**
     * Границы месяца по имени листа `requested_actions_<branch>_<Y_m>`.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public function leafBounds(string $leaf): array
    {
        if (! preg_match('/_(\d{4})_(\d{2})$/', $leaf, $matches)) {
            throw new \InvalidArgumentException("Leaf name '{$leaf}' is not a monthly partition");
        }

        $from = new DateTimeImmutable(
            sprintf('%s-%s-01 00:00:00', $matches[1], $matches[2]),
            new DateTimeZone('UTC'),
        );

        return [$from, $from->modify('+1 month')];
    }

    public function branchOf(string $leaf): string
    {
        return str_starts_with($leaf, 'requested_actions_active_') ? self::ACTIVE : self::COMPLETE;
    }

    public function parentOf(string $branch): string
    {
        return sprintf('requested_actions_%s', $branch);
    }

    /**
     * DETACH CONCURRENTLY и DROP — две отдельные транзакции (2.8.2).
     * При падении между ними лист остаётся сиротой и будет добит
     * recovery-фазой следующего запуска.
     */
    public function detachAndDrop(string $leaf): void
    {
        $parent = $this->parentOf($this->branchOf($leaf));

        // На случай незавершённого DETACH CONCURRENTLY (FINALIZE идемпотентен).
        $this->db->connection()->statement(
            sprintf('ALTER TABLE %s DETACH PARTITION %s FINALIZE', $parent, $leaf),
        );

        $this->db->connection()->statement(
            sprintf('ALTER TABLE %s DETACH PARTITION %s CONCURRENTLY', $parent, $leaf),
        );

        $this->db->connection()->statement(sprintf('DROP TABLE IF EXISTS %s', $leaf));
    }

    public function dropOrphan(string $leaf): void
    {
        $this->db->connection()->statement(sprintf('DROP TABLE IF EXISTS %s', $leaf));
    }

    private function ensure(string $branch, DateTimeImmutable $moment): string
    {
        $start = $moment->setTimezone(new DateTimeZone('UTC'))
            ->modify('first day of this month')
            ->setTime(0, 0, 0);
        $end = $start->modify('+1 month');

        $leaf = sprintf('requested_actions_%s_%s', $branch, $start->format('Y_m'));

        $this->db->connection()->statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
            $leaf,
            $this->parentOf($branch),
            $this->db->connection()->getPdo()->quote($start->format('Y-m-d H:i:sP')),
            $this->db->connection()->getPdo()->quote($end->format('Y-m-d H:i:sP')),
        ));

        if ($branch === self::ACTIVE) {
            // UNIQUE на листе: идемпотентность создания (2.8.1).
            $this->db->connection()->statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (user_id, handler, payload_hash)',
                "{$leaf}_identity_unique",
                $leaf,
            ));
        }

        $this->db->connection()->statement(sprintf(
            "CREATE INDEX IF NOT EXISTS %s ON %s (status, updated_at) WHERE status = 'approved'",
            "{$leaf}_approved_idx",
            $leaf,
        ));

        return $leaf;
    }
}
