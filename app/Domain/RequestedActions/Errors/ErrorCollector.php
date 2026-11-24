<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Errors;

use App\Domain\RequestedActions\Entities\RequestedAction;
use App\Domain\RequestedActions\Events\ActionError;
use App\Domain\RequestedActions\Exceptions\RequestedActionDomainException;
use App\Domain\RequestedActions\Exceptions\RequestedActionFatalException;
use DateTimeImmutable;
use Throwable;

/**
 * Общий сборщик ошибок контекста RequestedAction (раздел 1 «Сбор ошибок», 2.9).
 *
 * Классификация — по ТИПУ исключения, не по тексту:
 *  - RequestedActionDomainException — проброс наружу, статус не меняется,
 *    ActionError не эмитится;
 *  - RequestedActionFatalException — * → error, error_code/error_detail,
 *    сохранить агрегат, ActionError;
 *  - прочее (в т.ч. RequestedActionRetryableException после исчерпания retry) —
 *    трактуется как постоянная: error + ActionError.
 *
 * `handle()` и `sendErrorNotifications()` через коллектор НЕ проходят
 * (раздел 1): их ошибки обрабатывает HandleApprovedAction / stale-recovery.
 */
final readonly class ErrorCollector
{
    public const FATAL_UNCLASSIFIED = 'FATAL_UNCLASSIFIED';

    /**
     * Классифицирует ошибку. Возвращает `true`, если исключение доменное
     * и должно быть проброшено наружу без перевода агрегата в error.
     */
    public function isDomain(Throwable $e): bool
    {
        return $e instanceof RequestedActionDomainException;
    }

    /**
     * Переводит агрегат в error по факту не-доменной ошибки.
     *
     * @throws Throwable доменную ошибку пробрасывает наружу
     */
    public function collect(RequestedAction $action, Throwable $e, DateTimeImmutable $now): RequestedAction
    {
        if ($this->isDomain($e)) {
            throw $e;
        }

        [$code, $detail] = $this->classify($e);

        return $action->markError($code, $detail, $now);
    }

    /**
     * Собрать событие ActionError по уже сохранённому в error агрегату.
     */
    public function errorEvent(RequestedAction $action, DateTimeImmutable $now): ActionError
    {
        if ($action->id() === null) {
            throw new \LogicException('Cannot build ActionError for unsaved RequestedAction');
        }

        return new ActionError(
            requestedActionId: $action->id()->value,
            handler: $action->handler(),
            userId: $action->userId()->value,
            errorCode: $action->errorCode() ?? self::FATAL_UNCLASSIFIED,
            errorDetail: $action->errorDetail() ?? '',
            errorAt: $now,
        );
    }

    /**
     * @return array{0: string, 1: string} [error_code, error_detail]
     */
    private function classify(Throwable $e): array
    {
        $code = $e instanceof RequestedActionFatalException
            ? $e->errorCode()
            : self::FATAL_UNCLASSIFIED;

        return [$code, $e->getMessage()];
    }
}
