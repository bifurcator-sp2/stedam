<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Domain\RequestedActions\Entities\RequestedAction;
use App\Domain\RequestedActions\Enums\RequestedActionStatus;
use App\Domain\RequestedActions\Exceptions\ConcurrentModificationException;
use App\Domain\RequestedActions\ValueObjects\RequestedActionId;
use App\Infrastructure\Persistence\Mappers\RequestedActionMapper;
use App\Models\RequestedAction as RequestedActionModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;

/**
 * Eloquent-реализация репозитория агрегата RequestedAction (2.7, 2.8, 2.8.1).
 *
 * Особенности партиционирования:
 *  - поиск идёт по (id, created_at) без фильтра `complete` — после
 *    reject()/done() строка физически переезжает в другую ветку (2.15 п.23);
 *  - создание: INSERT в активный помесячный лист с `ON CONFLICT DO NOTHING`
 *    БЕЗ списка колонок — иначе PG не подхватит UNIQUE, объявленный на листе;
 *  - UPDATE статуса и сдвиг `complete` — разные стейтменты: `complete` —
 *    ключ партиционирования LIST.
 */
final readonly class EloquentRequestedActionRepository implements RequestedActionRepositoryInterface
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function find(int $id): ?RequestedAction
    {
        // id уникален только внутри ветки (PK — (id, complete, created_at)),
        // поэтому фильтруем обе ветки прямо на родителе: без `complete = ?`
        // сканирование всех листьев подряд.
        $row = $this->db->connection()->selectOne(
            'SELECT * FROM requested_actions WHERE complete = false AND id = ?
             UNION ALL
             SELECT * FROM requested_actions WHERE complete = true AND id = ?
             LIMIT 1',
            [$id, $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findForUpdate(int $id): ?RequestedAction
    {
        // Запреты PG: FOR UPDATE нельзя применить ни к UNION, ни к
        // партиционированному родителю. Поэтому две точки блокировки —
        // активная и завершённая ветки, каждая через листья (FROM ONLY
        // вызывать нельзя), и возвращается первая найденная строка.
        $sql = 'SELECT * FROM requested_actions WHERE complete = %s AND id = ? LIMIT 1 FOR UPDATE';

        $row = $this->db->connection()->selectOne(sprintf($sql, 'false'), [$id])
            ?? $this->db->connection()->selectOne(sprintf($sql, 'true'), [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findActiveByIdentity(int $userId, string $handler, string $payloadHash): ?RequestedAction
    {
        $row = $this->db->connection()->table('requested_actions')
            ->where('user_id', $userId)
            ->where('handler', $handler)
            ->where('payload_hash', $payloadHash)
            ->where('complete', false)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function insertActive(RequestedAction $action): bool
    {
        $attributes = RequestedActionMapper::toInsertAttributes($action);

        // ON CONFLICT DO NOTHING — БЕЗ списка колонок (2.8.1, 2.15 п.13).
        $row = $this->db->connection()->selectOne(
            'INSERT INTO requested_actions_active
                (user_id, handler, payload, status, decision, complete, error_code, error_detail)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT DO NOTHING
             RETURNING id',
            [
                $attributes['user_id'],
                $attributes['handler'],
                $attributes['payload'],
                $attributes['status'],
                $attributes['decision'],
                $attributes['complete'],
                $attributes['error_code'],
                $attributes['error_detail'],
            ],
        );

        return $row !== null;
    }

    public function save(RequestedAction $action): RequestedAction
    {
        $id = $this->requireId($action)->value;

        $affected = $this->db->connection()->table('requested_actions')
            ->where('id', $id)
            ->where('status', $this->originalStatus($action)->value)
            ->update(RequestedActionMapper::toUpdateAttributes($action));

        if ($affected === 0) {
            throw ConcurrentModificationException::forId($id);
        }

        return $action;
    }

    public function markComplete(RequestedAction $action): void
    {
        // Микросекунды обязательны: timestamptz хранит их, а `created_at = ?`
        // с точностью до секунды не совпадёт и UPDATE обновил бы 0 строк.
        $this->markCompleteById(
            $this->requireId($action)->value,
            $this->requireCreatedAt($action)->format('Y-m-d H:i:s.uP'),
        );
    }

    public function nextApprovedBatch(int $limit): array
    {
        $rows = $this->db->connection()->select(
            "SELECT * FROM requested_actions
              WHERE status = 'approved' AND complete = false
              ORDER BY updated_at ASC
              LIMIT ?
              FOR UPDATE SKIP LOCKED",
            [$limit],
        );

        return array_map(fn (object $row): RequestedAction => $this->hydrate($row), $rows);
    }

    public function nextCreatedBatch(int $limit): array
    {
        $rows = $this->db->connection()->select(
            "SELECT * FROM requested_actions
              WHERE status = 'created' AND complete = false
              ORDER BY updated_at ASC
              LIMIT ?
              FOR UPDATE SKIP LOCKED",
            [$limit],
        );

        return array_map(fn (object $row): RequestedAction => $this->hydrate($row), $rows);
    }

    public function lockStaleApprovedCandidates(
        int $notStartedOlderThanSeconds,
        int $retryExhaustedOlderThanSeconds,
        int $limit,
    ): array {
        // Сначала блокируем строки, которые прямо сейчас не обрабатывает
        // handle-approved (SKIP LOCKED), и только потом решаем, к какому
        // порогу относится каждая (2.8.2).
        $rows = $this->db->connection()->select(
            "SELECT * FROM requested_actions
              WHERE status = 'approved' AND complete = false
              ORDER BY updated_at ASC
              LIMIT ?
              FOR UPDATE SKIP LOCKED",
            [$limit],
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $result = ['T1' => [], 'T2' => []];

        foreach ($rows as $row) {
            $age = $now->getTimestamp() - (new DateTimeImmutable((string) $row->updated_at))->getTimestamp();
            $hasAttempts = $this->hasAttempts((int) $row->id, (bool) $row->complete, (string) $row->created_at);

            if (! $hasAttempts && $age >= $notStartedOlderThanSeconds) {
                $result['T1'][] = ['id' => (int) $row->id, 'created_at' => (string) $row->created_at];
            } elseif ($hasAttempts && $age >= $retryExhaustedOlderThanSeconds) {
                $result['T2'][] = ['id' => (int) $row->id, 'created_at' => (string) $row->created_at];
            }
        }

        return $result;
    }

    public function markStaleError(int $id, string $createdAt, string $errorCode, string $errorDetail): ?array
    {
        $row = $this->db->connection()->selectOne(
            "UPDATE requested_actions
                SET status = 'error', error_code = ?, error_detail = ?, updated_at = now()
              WHERE id = ? AND created_at = ? AND status = 'approved' AND complete = false
              RETURNING id, handler, user_id, created_at",
            [$errorCode, $errorDetail, $id, $createdAt],
        );

        return $row === null ? null : [
            'id' => (int) $row->id,
            'handler' => (string) $row->handler,
            'user_id' => (int) $row->user_id,
            'created_at' => (string) $row->created_at,
        ];
    }

    public function markCompleteById(int $id, string $createdAt): void
    {
        $this->db->connection()->update(
            'UPDATE requested_actions
                SET complete = true, updated_at = now()
              WHERE id = ? AND created_at = ? AND complete = false',
            [$id, $createdAt],
        );
    }

    // === Внутренние помощники ===

    private function hydrate(object $row): RequestedAction
    {
        // Собираем агрегат из «сырой» строки, минуя касты Eloquent: $dateFormat
        // моделей (`Y-m-d H:i:s P`) обнуляет микросекунды timestamptz, а по
        // `created_at = ?` (с микросекундами) идут UPDATE-ы markCompleteById и
        // stale-recovery — со «срезанной» секундой они не находят строку.
        return RequestedActionMapper::toDomainFromRow((array) $row);
    }

    /**
     * Статус, который был в строке до мутации. Агрегат иммутабелен и не
     * хранит предыдущий статус, поэтому оптимистичное условие выводится
     * из целевого статуса (2.15 п.2, 2.7).
     */
    private function originalStatus(RequestedAction $action): RequestedActionStatus
    {
        return match ($action->status()) {
            RequestedActionStatus::Approved => RequestedActionStatus::Pending,
            RequestedActionStatus::Rejected => RequestedActionStatus::Pending,
            RequestedActionStatus::Done => RequestedActionStatus::Approved,
            RequestedActionStatus::Pending => RequestedActionStatus::Created,
            RequestedActionStatus::Error => RequestedActionStatus::Approved,
            RequestedActionStatus::Created => RequestedActionStatus::Created,
        };
    }

    private function hasAttempts(int $id, bool $complete, string $createdAt): bool
    {
        return $this->db->connection()->table('requested_action_handle_attempts')
            ->where('requested_action_id', $id)
            ->where('requested_action_complete', $complete)
            ->where('requested_action_created_at', $createdAt)
            ->exists();
    }

    private function requireId(RequestedAction $action): RequestedActionId
    {
        $id = $action->id();

        if ($id === null) {
            throw new \LogicException('RequestedAction must be saved before repository update');
        }

        return $id;
    }

    private function requireCreatedAt(RequestedAction $action): DateTimeImmutable
    {
        $createdAt = $action->createdAt();

        if ($createdAt === null) {
            throw new \LogicException('RequestedAction must be saved before repository update');
        }

        return $createdAt;
    }
}
