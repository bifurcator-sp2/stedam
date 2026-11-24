<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Handlers;

use App\Domain\RequestedActions\DTOs\RegisterApprovalOutcome;
use App\Domain\RequestedActions\Entities\RequestedAction;
use App\Domain\RequestedActions\Exceptions\RequestedActionFatalException;
use App\Domain\RequestedActions\ValueObjects\QuorumSnapshot;
use App\Domain\RequestedActions\ValueObjects\UserId;

/**
 * Контракт обработчика RequestedAction (раздел 1 «Обработчик RequestedAction»,
 * 2.5).
 *
 * Один класс = один alias. Обработчик — политика: он сам решает, какая форма
 * запроса показывается, кто приёмщики, каким каналом доставлять и как считать
 * кворум. Application-слой даёт ему транзакцию, блокировку строки и
 * публикацию событий.
 *
 * Правила (раздел 1):
 *  - все методы, КРОМЕ handle() и sendErrorNotifications(), при броске
 *    исключения идут через ErrorCollector;
 *  - handle() обязан быть идемпотентным, при невозможности — FatalException;
 *  - геттеры форм бросают DomainException для пользователя вне consumers.
 */
interface RequestedActionHandler
{
    /**
     * Может ли текущий пользователь открыть форму запроса.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function canGetRequestForm(array $arguments): bool;

    /**
     * Данные формы запроса для интерфейса инициатора.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function getRequestForm(array $arguments): array;

    /**
     * Разбор ответа формы запроса: создать агрегат (true) или отказаться (false).
     * Возвращает итоговый payload агрегата (с обязательным ключом consumers)
     * или null, если пользователь отказался от действия.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    public function registerUserRequest(array $arguments): ?array;

    /**
     * Разослать уведомления с запросом приёмщикам. Вызывается воркером
     * messaging, не контроллером (2.15 п.5).
     */
    public function sendRequestNotifications(RequestedAction $action): void;

    /**
     * @return list<UserId>
     */
    public function getRequestNotificationConsumers(RequestedAction $action): array;

    public function getRequestNotificationMessage(UserId $consumer, RequestedAction $action): array;

    /**
     * @return list<string>
     */
    public function getNotificationChannels(UserId $consumer): array;

    /**
     * Может ли текущий пользователь аппрувить конкретно это предложение.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function canGetApprovalForm(RequestedAction $action, UserId $responder, array $arguments): bool;

    /**
     * Данные формы принятия для интерфейса приёмщика.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function getApprovalForm(RequestedAction $action, UserId $responder, array $arguments): array;

    /**
     * Ядро принятия решения (раздел 1, 2.4.2):
     *  1) status ≠ pending → CLOSED;
     *  2) responder ∉ consumers → DomainException (403);
     *  3) INSERT голоса; конфликт → AlreadyVotedException (409);
     *  4) snapshot = votes->snapshot(...);
     *  5) isSatisfied && handleApproved → markApproved + ACCEPTED;
     *     isImpossible → reject + DECLINED; иначе WAITING.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function registerApproval(
        RequestedAction $action,
        UserId $responder,
        array $arguments,
    ): RegisterApprovalOutcome;

    /**
     * Кворумный порог: сколько accept-голосов достаточно для ACCEPTED.
     */
    public function quorumSize(RequestedAction $action): int;

    /**
     * Политика «считать ли кворум сошедшимся». Вызывается внутри registerApproval.
     */
    public function handleApproved(QuorumSnapshot $snapshot): bool;

    /**
     * pending → rejected + complete=true. Вызывается только из registerApproval.
     */
    public function reject(RequestedAction $action): RequestedAction;

    public function sendApprovalNotifications(RequestedAction $action): void;

    public function getApprovedNotificationMessage(UserId $consumer, RequestedAction $action): array;

    public function sendRejectionNotifications(RequestedAction $action): void;

    public function getRejectedNotificationMessage(UserId $consumer, RequestedAction $action): array;

    public function sendDoneNotifications(RequestedAction $action): void;

    public function getDoneNotificationMessage(UserId $consumer, RequestedAction $action): array;

    public function sendErrorNotifications(RequestedAction $action): void;

    public function getErrorNotificationMessage(UserId $consumer, RequestedAction $action): array;

    /**
     * Физическое выполнение действия. Должен быть идемпотентным.
     *
     * @throws RequestedActionFatalException
     */
    public function handle(RequestedAction $action): void;
}
