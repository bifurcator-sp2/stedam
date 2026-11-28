<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Application\RequestedActions\Ports\RequestedActionHandleAttemptsRepositoryInterface;
use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Application\Shared\Clock;
use App\Application\Shared\TransactionManagerInterface;
use App\Domain\RequestedActions\Entities\RequestedAction;
use App\Domain\RequestedActions\Enums\RequestedActionStatus;
use App\Domain\RequestedActions\Errors\ErrorCollector;
use App\Domain\RequestedActions\Events\ActionDone;
use App\Domain\RequestedActions\Events\ActionError;
use App\Domain\RequestedActions\Exceptions\RequestedActionFatalException;
use App\Domain\RequestedActions\Exceptions\RequestedActionRetryableException;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Исполнение одного approved-агрегата (2.6, раздел 1 «handle_approved»).
 *
 * Две транзакции — принципиально:
 *  А. INSERT строки попытки и коммит. Так «handle() запускался» фиксируется
 *     даже при полном откате транзакции Б, и stale-recovery может отличить
 *     «не запускался» от «запускался и падал».
 *  Б. Работа с агрегатом: handle() → done + complete=true, либо error
 *     (complete остаётся false — его ставит только stale-recovery).
 *
 * После коммита Б: finish(attempt, outcome) и одно событие ActionDone|ActionError.
 * RetryableException: Б откатывается, attempt закрывается с кодом ошибки,
 * исключение пробрасывается наружу — воркер ретраит по $tries/$backoff.
 */
final readonly class HandleApprovedAction
{
    public function __construct(
        private RequestedActionRepositoryInterface $repository,
        private RequestedActionHandleAttemptsRepositoryInterface $attempts,
        private RequestedActionHandlerRegistryInterface $handlers,
        private EnsureRequestedActionPartitionAction $partitions,
        private TransactionManagerInterface $transactions,
        private Dispatcher $events,
        private Clock $clock,
    ) {}

    /**
     * @return bool true — агрегат обработан этим вызовом; false — уже не approved
     */
    public function execute(int $id): bool
    {
        $action = $this->repository->find($id);

        if ($action === null || $action->status() !== RequestedActionStatus::Approved) {
            return false;
        }

        $ref = $action->ref();

        // А. Фиксация попытки до вызова handle().
        $attemptId = $this->transactions->transactional(
            fn (): int => $this->attempts->start($ref),
        );

        try {
            // Б. Работа с агрегатом.
            $processed = $this->transactions->transactional(
                fn (): ?RequestedAction => $this->process($id),
            );
        } catch (RequestedActionRetryableException $e) {
            // Б откатилась; попытка из А уже зафиксирована.
            $this->attempts->finish($attemptId, $this->errorCode($e));

            throw $e;
        } catch (Throwable $e) {
            $this->attempts->finish($attemptId, $this->errorCode($e));

            throw $e;
        }

        if ($processed === null) {
            $this->attempts->finish($attemptId, 'ok');

            return false;
        }

        $this->attempts->finish($attemptId, $this->attemptOutcome($processed));
        $this->dispatchEvent($processed);

        return true;
    }

    private function process(int $id): ?RequestedAction
    {
        $action = $this->repository->findForUpdate($id);

        if ($action === null || $action->status() !== RequestedActionStatus::Approved) {
            return null;
        }

        $now = $this->clock->now();
        $handler = $this->handlers->resolve($action->handler());

        try {
            $handler->handle($action);
        } catch (RequestedActionRetryableException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->markError($action, $e, $now);
        }

        $done = $action->markDone($now);

        // Статус и complete — два UPDATE в правильном порядке.
        $this->saveDone($done, $now);

        return $done;
    }

    private function markError(RequestedAction $action, Throwable $e, DateTimeImmutable $now): RequestedAction
    {
        $errored = $action->markError($this->errorCode($e), $e->getMessage(), $now);

        // complete не трогаем (2.15 п.1).
        $this->repository->save($errored, $action->status());

        return $errored;
    }

    /**
     * approved → done + переезд строки в завершённую ветку.
     *
     * `complete` — ключ партиционирования LIST, поэтому его смена — отдельный
     * UPDATE и отдельная транзакция: в одной транзакции повторное обращение
     * к только что обновлённой строке даёт «tuple to be updated was already
     * modified by a concurrent operation».
     *
     * DDL листа завершённой ветки выполняется между транзакциями.
     */
    private function saveDone(RequestedAction $done, DateTimeImmutable $now): void
    {
        $this->transactions->transactional(
            fn () => $this->repository->save($done, RequestedActionStatus::Approved),
        );

        $this->partitions->executeComplete($now);

        $this->repository->markComplete($done);
    }

    private function dispatchEvent(RequestedAction $action): void
    {
        $now = $this->clock->now();
        $id = $action->id()?->value ?? 0;

        if ($action->status() === RequestedActionStatus::Done) {
            $this->events->dispatch(new ActionDone(
                requestedActionId: $id,
                handler: $action->handler(),
                userId: $action->userId()->value,
                doneAt: $action->updatedAt() ?? $now,
            ));

            return;
        }

        if ($action->status() === RequestedActionStatus::Error) {
            $this->events->dispatch(new ActionError(
                requestedActionId: $id,
                handler: $action->handler(),
                userId: $action->userId()->value,
                errorCode: $action->errorCode() ?? ErrorCollector::FATAL_UNCLASSIFIED,
                errorDetail: $action->errorDetail() ?? '',
                errorAt: $action->updatedAt() ?? $now,
            ));
        }
    }

    private function attemptOutcome(RequestedAction $action): string
    {
        return $action->status() === RequestedActionStatus::Error
            ? ($action->errorCode() ?? ErrorCollector::FATAL_UNCLASSIFIED)
            : 'ok';
    }

    private function errorCode(Throwable $e): string
    {
        return $e instanceof RequestedActionFatalException
            ? $e->errorCode()
            : ErrorCollector::FATAL_UNCLASSIFIED;
    }
}
