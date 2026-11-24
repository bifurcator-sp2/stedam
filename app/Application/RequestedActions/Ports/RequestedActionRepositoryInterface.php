<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Ports;

use App\Domain\RequestedActions\Entities\RequestedAction;

/**
 * Репозиторий агрегата RequestedAction (2.7).
 *
 * Особенности партиционирования (LIST(complete) → RANGE(created_at)):
 *  - поиск идёт по (id, created_at) без фильтра `complete`: строка
 *    переезжает между ветками (2.15 п.23);
 *  - UPDATE оптимистичный: `WHERE id = ? AND status = :oldStatus`;
 *  - идемпотентность создания — через UNIQUE на помесячном активном листе.
 */
interface RequestedActionRepositoryInterface
{
    public function find(int $id): ?RequestedAction;

    /**
     * SELECT ... FOR UPDATE — сериализует конкурентные registerApproval.
     */
    public function findForUpdate(int $id): ?RequestedAction;

    /**
     * Поиск существующего активного агрегата по ключу идемпотентности
     * (user_id, handler, payload_hash) в текущей активной помесячной партиции.
     */
    public function findActiveByIdentity(int $userId, string $handler, string $payloadHash): ?RequestedAction;

    /**
     * INSERT агрегата в активную помесячную субпартицию с
     * `ON CONFLICT DO NOTHING` (без списка колонок!).
     *
     * Возвращает true, если строка вставлена; false — если был конфликт
     * по UNIQUE (user_id, handler, payload_hash) на листе (2.8.1).
     */
    public function insertActive(RequestedAction $action): bool;

    /**
     * UPDATE существующего агрегата (оптимистичная блокировка).
     *
     * При `0 rows affected` выбрасывает ConcurrentModificationException.
     */
    public function save(RequestedAction $action): RequestedAction;

    /**
     * Сдвиг строки в завершённую ветку (complete = true) без смены статуса.
     * Отдельный UPDATE — ключ секционирования LIST нельзя менять в том же
     * стейтменте, что идёт через partition routing (2.15 п.19).
     */
    public function markComplete(RequestedAction $action): void;

    /**
     * Пакет approved-агрегатов для воркера handle: ORDER BY updated_at ASC,
     * FOR UPDATE SKIP LOCKED, LIMIT.
     *
     * @return list<RequestedAction>
     */
    public function nextApprovedBatch(int $limit): array;

    /**
     * Пакет created-агрегатов для messaging-воркера: FOR UPDATE SKIP LOCKED.
     *
     * @return list<RequestedAction>
     */
    public function nextCreatedBatch(int $limit): array;

    /**
     * Кандидаты для stale-recovery (2.8.2), уже под FOR UPDATE SKIP LOCKED.
     *
     * Возвращает два списка: без попыток (старше T1) и с попытками
     * (старше T2). `LOCKED`-строки исключаются на уровне SQL — параллельный
     * handle-approved не пересекается с recovery.
     *
     * @return array{
     *     T1: list<array{id:int, created_at:string}>,
     *     T2: list<array{id:int, created_at:string}>
     * }
     */
    public function lockStaleApprovedCandidates(
        int $notStartedOlderThanSeconds,
        int $retryExhaustedOlderThanSeconds,
        int $limit,
    ): array;

    /**
     * Перевести конкретный агрегат в error и вернуть данные для ActionError.
     *
     * @return array{id:int, handler:string, user_id:int, created_at:string}|null
     */
    public function markStaleError(int $id, string $createdAt, string $errorCode, string $errorDetail): ?array;

    /**
     * Сдвинуть строку в завершённую ветку по ключу идентичности.
     */
    public function markCompleteById(int $id, string $createdAt): void;
}
