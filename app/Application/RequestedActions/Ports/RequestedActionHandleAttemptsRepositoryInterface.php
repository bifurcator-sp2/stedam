<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Ports;

use App\Domain\RequestedActions\ValueObjects\RequestedActionRef;
use DateTimeImmutable;

/**
 * Порт таблицы `requested_action_handle_attempts` (2.7).
 *
 * Строка attempt коммитится ДО вызова handle() (транзакция А в
 * HandleApprovedAction) — так stale-recovery различает «не запускался»
 * и «запускался и падал» (раздел 1 «Stale approved»).
 */
interface RequestedActionHandleAttemptsRepositoryInterface
{
    /**
     * INSERT строки попытки (finished_at = NULL). Возвращает attemptId.
     */
    public function start(RequestedActionRef $ref): int;

    /**
     * Отметить попытку завершённой: finished_at = now(), outcome ('ok'|error_code).
     */
    public function finish(int $attemptId, string $outcome): void;

    public function hasAttempts(RequestedActionRef $ref): bool;

    /**
     * Очистка для помесячного листа [from, to) — retention (2.8.2).
     */
    public function deleteForLeaf(DateTimeImmutable $from, DateTimeImmutable $to): int;
}
