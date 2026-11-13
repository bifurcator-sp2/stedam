<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Shared\TransactionManagerInterface;
use Closure;

/**
 * Менеджер транзакций для тестов Application-слоя.
 *
 * Не открывает настоящую транзакцию: считает вызовы и запоминает, откатилась
 * ли операция. Этого достаточно, чтобы проверить главное свойство — что
 * Action вообще оборачивает работу в transactional() и что при исключении
 * результат не сохраняется вызывающим.
 *
 * rollback произойдёт при любом Throwable внутри операции.
 */
final class FakeTransactionManager implements TransactionManagerInterface
{
    public int $calls = 0;

    public bool $rolledBack = false;

    public bool $committed = false;

    /**
     * Колбэк, бросающий исключение на N-м (1-based) вызове transactional().
     * null — никогда не падать.
     */
    public ?int $failOnCall = null;

    /**
     * Снимок состояния до входа в транзакцию, чтобы откатить фейковые
     * репозитории. Выставляется тестом, если нужен реальный откат.
     *
     * @var (Closure(): void)|null
     */
    private ?Closure $rollbackCallback = null;

    /**
     * Зарегистрировать «откат» для фейковых хранилищ.
     *
     * Вызывается ДО transactional(), колбэк отдаёт состояние, к которому
     * надо вернуться при исключении.
     */
    public function onRollback(Closure $restore): void
    {
        $this->rollbackCallback = $restore;
    }

    public function transactional(Closure $operation): mixed
    {
        $this->calls++;

        if ($this->failOnCall !== null && $this->calls === $this->failOnCall) {
            $this->rolledBack = true;
            $this->rollbackCallback?->__invoke();

            throw new \RuntimeException('FakeTransactionManager: forced failure');
        }

        try {
            $result = $operation();
        } catch (\Throwable $e) {
            $this->rolledBack = true;
            $this->rollbackCallback?->__invoke();

            throw $e;
        }

        $this->committed = true;

        return $result;
    }
}
