<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mappers;

use App\Domain\RequestedActions\Entities\RequestedAction as DomainRequestedAction;
use App\Domain\RequestedActions\Enums\RequestedActionStatus;
use App\Domain\RequestedActions\Enums\VoteDecision;
use App\Domain\RequestedActions\ValueObjects\RequestedActionId;
use App\Domain\RequestedActions\ValueObjects\RequestedActionPayload;
use App\Domain\RequestedActions\ValueObjects\UserId;
use App\Models\RequestedAction as RequestedActionModel;
use DateTimeImmutable;

/**
 * Маппинг строки `requested_actions` ↔ доменный агрегат (2.7).
 *
 * Только чистое преобразование: ни запросов, ни транзакций.
 */
final class RequestedActionMapper
{
    public static function toDomain(RequestedActionModel $model): DomainRequestedAction
    {
        $payload = $model->payload;

        if (is_string($payload)) {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($payload) ? $payload : [];

        $status = $model->status;
        $createdAt = $model->created_at;
        $updatedAt = $model->updated_at;

        return DomainRequestedAction::restore(
            id: new RequestedActionId((int) $model->id),
            userId: new UserId((int) $model->user_id),
            handler: (string) $model->handler,
            payload: new RequestedActionPayload($payload),
            status: $status instanceof RequestedActionStatus
                ? $status
                : RequestedActionStatus::from((string) $status),
            decision: $model->decision !== null
                ? ($model->decision instanceof VoteDecision
                    ? $model->decision
                    : VoteDecision::from((string) $model->decision))
                : null,
            complete: (bool) $model->complete,
            errorCode: $model->error_code !== null ? (string) $model->error_code : null,
            errorDetail: $model->error_detail !== null ? (string) $model->error_detail : null,
            createdAt: $createdAt instanceof DateTimeImmutable
                ? $createdAt
                : new DateTimeImmutable((string) $createdAt),
            updatedAt: $updatedAt instanceof DateTimeImmutable
                ? $updatedAt
                : new DateTimeImmutable((string) $updatedAt),
        );
    }

    /**
     * Гидрация из «сырой» строки БД (ассоциативный массив), без Eloquent-кастов.
     *
     * Даты берутся как есть (строки timestamptz с микросекундами), чтобы
     * дальше `created_at` участвовал в UPDATE-условиях с полной точностью.
     *
     * @param  array<string, mixed>  $row
     */
    public static function toDomainFromRow(array $row): DomainRequestedAction
    {
        $payload = $row['payload'] ?? [];

        if (is_string($payload)) {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($payload) ? $payload : [];

        return DomainRequestedAction::restore(
            id: new RequestedActionId((int) $row['id']),
            userId: new UserId((int) $row['user_id']),
            handler: (string) $row['handler'],
            payload: new RequestedActionPayload($payload),
            status: RequestedActionStatus::from((string) $row['status']),
            decision: ($row['decision'] ?? null) !== null
                ? VoteDecision::from((string) $row['decision'])
                : null,
            complete: (bool) $row['complete'],
            errorCode: ($row['error_code'] ?? null) !== null ? (string) $row['error_code'] : null,
            errorDetail: ($row['error_detail'] ?? null) !== null ? (string) $row['error_detail'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    /**
     * Атрибуты для INSERT (без id/payload_hash/created_at — их задаёт БД).
     *
     * @return array<string, mixed>
     */
    public static function toInsertAttributes(DomainRequestedAction $action): array
    {
        return [
            'user_id' => $action->userId()->value,
            'handler' => $action->handler(),
            // JSON-строка: драйвер PG отдаёт jsonb как текст, поэтому payload_hash
            // (md5 от канонической формы jsonb) считается сервером одинаково.
            'payload' => json_encode($action->payload()->toArray(), JSON_THROW_ON_ERROR),
            'status' => $action->status()->value,
            'decision' => $action->decision()?->value,
            'complete' => $action->isComplete(),
            'error_code' => $action->errorCode(),
            'error_detail' => $action->errorDetail(),
        ];
    }

    /**
     * Атрибуты для UPDATE (payload иммутабелен — не входит, 2.15 п.3).
     *
     * `decision` здесь не передаётся: неизменяемый агрегат не знает
     * предыдущего значения, а `markApproved()/reject()` его перезаписывают.
     *
     * @return array<string, mixed>
     */
    public static function toUpdateAttributes(DomainRequestedAction $action): array
    {
        return [
            'status' => $action->status()->value,
            'decision' => $action->decision()?->value,
            'error_code' => $action->errorCode(),
            'error_detail' => $action->errorDetail(),
            'updated_at' => $action->updatedAt(),
        ];
    }
}
