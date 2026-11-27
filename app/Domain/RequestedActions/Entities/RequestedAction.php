<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Entities;

use App\Domain\RequestedActions\Enums\RequestedActionStatus;
use App\Domain\RequestedActions\Enums\VoteDecision;
use App\Domain\RequestedActions\Exceptions\InvalidStatusTransitionException;
use App\Domain\RequestedActions\ValueObjects\RequestedActionId;
use App\Domain\RequestedActions\ValueObjects\RequestedActionPayload;
use App\Domain\RequestedActions\ValueObjects\RequestedActionRef;
use App\Domain\RequestedActions\ValueObjects\UserId;
use DateTimeImmutable;

/**
 * Агрегат RequestedAction — «действие по запросу» (раздел 1 + 2.3).
 *
 * Создаётся в created, живёт в партиционированной таблице
 * `requested_actions` (LIST(complete) → RANGE(created_at)):
 *   - created : messaging worker ещё не разослал запрос приёмщикам;
 *   - pending : запрос разослан, ждём голосов;
 *   - approved: кворум собран;
 *   - rejected: кворум недостижим (терминальный);
 *   - done    : действие физически выполнено (терминальный);
 *   - error   : ошибка пайпа, дальнейшие действия запрещены (терминальный).
 *
 * Инварианты (раздел 2.3):
 *  - переходы только created→pending, pending→approved, pending→rejected,
 *    approved→done, *→error (кроме терминальных); иное — доменная ошибка;
 *  - payload иммутабелен после создания;
 *  - reject идемпотентен по состоянию: повтор на rejected → та же «закрытость».
 *
 * Агрегат НЕ публикует события и не зависит от Laravel: события создаёт
 * Application-слой после сохранения (конвенция проекта, см. Course).
 *
 * Иммутабелен: каждая мутация возвращает новый экземпляр.
 */
final class RequestedAction
{
    private function __construct(
        private ?RequestedActionId $id,
        private UserId $userId,
        private string $handler,
        private RequestedActionPayload $payload,
        private RequestedActionStatus $status,
        private ?VoteDecision $decision,
        private bool $complete,
        private ?string $errorCode,
        private ?string $errorDetail,
        private ?DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Создание нового агрегата: всегда created, complete = false.
     *
     * `payload` обязан содержать ключ consumers — иначе RequestedActionPayload
     * не позволит его прочитать, а валидацию при создании проводит
     * CreateRequestedActionAction; здесь фиксируем инвариант «created».
     */
    public static function create(
        UserId $userId,
        string $handler,
        RequestedActionPayload $payload,
    ): self {
        return new self(
            id: null,
            userId: $userId,
            handler: $handler,
            payload: $payload,
            status: RequestedActionStatus::Created,
            decision: null,
            complete: false,
            errorCode: null,
            errorDetail: null,
            createdAt: null,
            updatedAt: null,
        );
    }

    /**
     * Гидрация из репозитория (все поля обязательны, id известен).
     */
    public static function restore(
        RequestedActionId $id,
        UserId $userId,
        string $handler,
        RequestedActionPayload $payload,
        RequestedActionStatus $status,
        ?VoteDecision $decision,
        bool $complete,
        ?string $errorCode,
        ?string $errorDetail,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            handler: $handler,
            payload: $payload,
            status: $status,
            decision: $decision,
            complete: $complete,
            errorCode: $errorCode,
            errorDetail: $errorDetail,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    // === Геттеры ===

    public function id(): ?RequestedActionId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function handler(): string
    {
        return $this->handler;
    }

    public function payload(): RequestedActionPayload
    {
        return $this->payload;
    }

    public function status(): RequestedActionStatus
    {
        return $this->status;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Итоговое решение кворума по данным requested_action_votes (раздел 1).
     * null — кворум ещё не принял решения (created/pending/error).
     */
    public function decision(): ?VoteDecision
    {
        return $this->decision;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorDetail(): ?string
    {
        return $this->errorDetail;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isPending(): bool
    {
        return $this->status === RequestedActionStatus::Pending;
    }

    /**
     * Ключ идентичности строки для votes/attempts/retention (2.7).
     * Несохранённый агрегат id не имеет — программная ошибка (\LogicException).
     */
    public function ref(): RequestedActionRef
    {
        if ($this->id === null || $this->createdAt === null) {
            throw new \LogicException('RequestedAction must be saved before ref() is available');
        }

        return new RequestedActionRef(
            id: $this->id->value,
            complete: $this->complete,
            createdAt: $this->createdAt,
        );
    }

    // === Мутации ===

    /**
     * Присвоить id и timestamps после INSERT (insert-repository).
     */
    public function withId(
        RequestedActionId $id,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            id: $id,
            userId: $this->userId,
            handler: $this->handler,
            payload: $this->payload,
            status: $this->status,
            decision: $this->decision,
            complete: $this->complete,
            errorCode: $this->errorCode,
            errorDetail: $this->errorDetail,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    /**
     * created → pending: сообщения приёмщикам разосланы.
     */
    public function markPending(DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(RequestedActionStatus::Pending);

        return $this->copy(
            status: RequestedActionStatus::Pending,
            updatedAt: $now,
        );
    }

    /**
     * pending → approved: кворум собран. complete остаётся false:
     * агрегат удаляется из активной ветки retention'ом по created_at.
     */
    public function markApproved(DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(RequestedActionStatus::Approved);

        return $this->copy(
            status: RequestedActionStatus::Approved,
            decision: VoteDecision::Accepted,
            decisionIsSet: true,
            updatedAt: $now,
        );
    }

    /**
     * pending → rejected: кворум недостижим. complete = true (переезд в
     * завершённую ветку). Повторный reject в том же состоянии безопасен.
     */
    public function reject(DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(RequestedActionStatus::Rejected);

        return $this->copy(
            status: RequestedActionStatus::Rejected,
            decision: VoteDecision::Declined,
            decisionIsSet: true,
            complete: true,
            completeIsSet: true,
            updatedAt: $now,
        );
    }

    /**
     * approved → done: действие физически выполнено. complete = true.
     */
    public function markDone(DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(RequestedActionStatus::Done);

        return $this->copy(
            status: RequestedActionStatus::Done,
            complete: true,
            completeIsSet: true,
            updatedAt: $now,
        );
    }

    /**
     * * → error. complete не меняется здесь (политика — в 2.15 п.1):
     * ErrorCollector оставляет активную ветку, stale-recovery ставит
     * complete=true отдельным UPDATE.
     */
    public function markError(string $errorCode, string $errorDetail, DateTimeImmutable $now): self
    {
        $this->assertCanTransitionTo(RequestedActionStatus::Error);

        return $this->copy(
            status: RequestedActionStatus::Error,
            errorCode: $errorCode,
            errorCodeIsSet: true,
            errorDetail: $errorDetail,
            errorDetailIsSet: true,
            updatedAt: $now,
        );
    }

    /**
     * Сдвиг строки в завершённую ветку без смены статуса — только для
     * stale-recovery (после markError отдельный UPDATE complete=true).
     */
    public function markComplete(DateTimeImmutable $now): self
    {
        return $this->copy(
            complete: true,
            completeIsSet: true,
            updatedAt: $now,
        );
    }

    // === Внутренние помощники ===

    private function assertCanTransitionTo(RequestedActionStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw InvalidStatusTransitionException::fromTo($this->status, $target);
        }
    }

    private function copy(
        ?RequestedActionStatus $status = null,
        ?VoteDecision $decision = null,
        bool $decisionIsSet = false,
        bool $complete = false,
        bool $completeIsSet = false,
        ?string $errorCode = null,
        bool $errorCodeIsSet = false,
        ?string $errorDetail = null,
        bool $errorDetailIsSet = false,
        ?DateTimeImmutable $updatedAt = null,
    ): self {
        return new self(
            id: $this->id,
            userId: $this->userId,
            handler: $this->handler,
            payload: $this->payload,
            status: $status ?? $this->status,
            decision: $decisionIsSet ? $decision : $this->decision,
            complete: $completeIsSet ? $complete : $this->complete,
            errorCode: $errorCodeIsSet ? $errorCode : $this->errorCode,
            errorDetail: $errorDetailIsSet ? $errorDetail : $this->errorDetail,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt ?? $this->updatedAt,
        );
    }
}
