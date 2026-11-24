<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Application\Shared\Clock;
use App\Application\Shared\TransactionManagerInterface;
use App\Domain\RequestedActions\Entities\RequestedAction;
use App\Domain\RequestedActions\Events\ActionRequested;
use App\Domain\RequestedActions\Exceptions\RequestedActionDomainException;
use App\Domain\RequestedActions\ValueObjects\RequestedActionPayload;
use App\Domain\RequestedActions\ValueObjects\UserId;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Создание запроса на действие (2.6, 2.8.1).
 *
 * Шаги:
 *  1. резолв handler'а по alias;
 *  2. `registerUserRequest()` — разбор ответа формы: null → пользователь
 *     отказался (HTTP 422 USER_REQUEST_DECLINED, агрегат не создаётся);
 *  3. INSERT в активный помесячный лист с ON CONFLICT DO NOTHING (без колонок);
 *  4. конфликт → возвращаем существующий активный агрегат, событие НЕ эмитим;
 *  5. новая строка → ActionRequested после commit.
 *
 * ActionRequested слушателей не имеет: запрос приёмщикам рассылает
 * requested-actions:messaging worker по статусу created (2.15 п.5).
 */
final readonly class CreateRequestedActionAction
{
    public function __construct(
        private RequestedActionRepositoryInterface $repository,
        private RequestedActionHandlerRegistryInterface $handlers,
        private EnsureRequestedActionPartitionAction $partitions,
        private TransactionManagerInterface $transactions,
        private Dispatcher $events,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return RequestedAction|null null — пользователь отказался от действия
     */
    public function execute(UserId $initiator, array $arguments, string $handlerAlias): ?RequestedAction
    {
        $handler = $this->handlers->resolve($handlerAlias);

        $payloadData = $handler->registerUserRequest($arguments);

        if ($payloadData === null) {
            return null;
        }

        if (! array_key_exists('consumers', $payloadData)) {
            throw new RequestedActionDomainException('payload.consumers is required');
        }

        $created = RequestedAction::create(
            userId: $initiator,
            handler: $handlerAlias,
            payload: new RequestedActionPayload($payloadData),
        );

        $now = $this->clock->now();

        // DDL партиции — вне транзакции: CREATE TABLE ... PARTITION OF
        // нельзя держать в той же транзакции, что и INSERT (блокировки
        // retention-воркера на родителе), и он идемпотентен.
        $this->partitions->executeActive($now);

        $result = $this->transactions->transactional(function () use ($created, $initiator, $handlerAlias): array {
            try {
                $inserted = $this->repository->insertActive($created);
            } catch (UniqueConstraintViolationException) {
                // Конфликт по UNIQUE листа (ON CONFLICT не совпал с индексом) —
                // трактуем как дубликат: возвращаем существующий агрегат.
                $inserted = false;
            }

            if (! $inserted) {
                return [
                    $this->repository->findActiveByIdentity(
                        $initiator->value,
                        $handlerAlias,
                        $this->payloadHash($created),
                    ),
                    false,
                ];
            }

            // Строку читаем обратно: INSERT ... RETURNING id возвращает id,
            // но остальные поля (created_at) нужны для ref() и для ответа.
            $row = $this->repository->findActiveByIdentity(
                $initiator->value,
                $handlerAlias,
                $this->payloadHash($created),
            );

            if ($row === null) {
                throw new \LogicException('Inserted RequestedAction cannot be read back');
            }

            return [$row, true];
        });

        /** @var RequestedAction|null $action */
        [$action, $isNew] = $result;

        if ($isNew && $action !== null) {
            $this->events->dispatch(new ActionRequested(
                requestedActionId: $action->id()?->value ?? 0,
                handler: $action->handler(),
                userId: $action->userId()->value,
                arguments: $arguments,
                createdAt: $action->createdAt() ?? $this->clock->now(),
            ));
        }

        return $action;
    }

    /**
     * Ключ идемпотентности должен совпадать с generated column
     * `payload_hash = md5(payload::text)` (2.8.1). jsonb канонизирует порядок
     * ключей и экранирование, поэтому локально повторяем каноническую форму:
     * сортировка ключей рекурсивно, `json_encode` без unicode-эскейпа.
     */
    private function payloadHash(RequestedAction $action): string
    {
        return md5($this->canonicalize($action->payload()->toArray()));
    }

    /**
     * Каноническая форма payload, совпадающая с jsonb::text:
     * ключи отсортированы рекурсивно, строки — в NFC, без unicode-эскейпа.
     *
     * @param  array<string, mixed>  $data
     */
    private function canonicalize(array $data): string
    {
        return json_encode($this->prepare($data), JSON_THROW_ON_ERROR);
    }

    private function prepare(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? $this->normalizeString($value) : $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->prepare($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->prepare($item);
        }

        return $value;
    }

    private function normalizeString(string $value): string
    {
        if (! class_exists(\Normalizer::class)) {
            return $value;
        }

        return \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
    }
}
