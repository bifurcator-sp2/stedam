<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\RequestedActions\Ports\RequestedActionHandleAttemptsRepositoryInterface;
use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Application\RequestedActions\Ports\RequestedActionRepositoryInterface;
use App\Application\RequestedActions\Ports\RequestedActionVotesRepositoryInterface;
use App\Domain\RequestedActions\Errors\ErrorCollector;
use App\Domain\RequestedActions\Events\ActionApproved;
use App\Domain\RequestedActions\Events\ActionDone;
use App\Domain\RequestedActions\Events\ActionError;
use App\Domain\RequestedActions\Events\ActionRejected;
use App\Infrastructure\Persistence\Repositories\EloquentRequestedActionHandleAttemptsRepository;
use App\Infrastructure\Persistence\Repositories\EloquentRequestedActionRepository;
use App\Infrastructure\Persistence\Repositories\EloquentRequestedActionVotesRepository;
use App\Infrastructure\RequestedActions\RequestedActionHandlerRegistry;
use App\Listeners\RequestedActions\SendApprovalNotifications;
use App\Listeners\RequestedActions\SendDoneNotifications;
use App\Listeners\RequestedActions\SendErrorNotifications;
use App\Listeners\RequestedActions\SendRejectionNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Биндинги контекста RequestedAction (2.5, 2.6, 2.10, 2.11).
 *
 * Application-порты → Infrastructure-реализации; `ErrorCollector` —
 * stateless-синглтон; доменные события связываются с асинхронными
 * слушателями уведомлений (`ShouldQueueAfterCommit`).
 *
 * `Clock` и `TransactionManagerInterface` уже забинжены в AppServiceProvider
 * и переиспользуются как есть.
 */
final class RequestedActionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RequestedActionRepositoryInterface::class, EloquentRequestedActionRepository::class);
        $this->app->bind(RequestedActionVotesRepositoryInterface::class, EloquentRequestedActionVotesRepository::class);
        $this->app->bind(
            RequestedActionHandleAttemptsRepositoryInterface::class,
            EloquentRequestedActionHandleAttemptsRepository::class,
        );
        $this->app->bind(RequestedActionHandlerRegistryInterface::class, RequestedActionHandlerRegistry::class);

        // Сборщик ошибок не хранит состояния — один экземпляр на запрос.
        $this->app->singleton(ErrorCollector::class);
    }

    public function boot(): void
    {
        // Уведомления по доменным событиям (2.10). Все слушатели —
        // ShouldQueueAfterCommit: реагируют только на зафиксированные
        // изменения и не тормозят HTTP-ответ.
        Event::listen(ActionApproved::class, SendApprovalNotifications::class);
        Event::listen(ActionRejected::class, SendRejectionNotifications::class);
        Event::listen(ActionDone::class, SendDoneNotifications::class);
        Event::listen(ActionError::class, SendErrorNotifications::class);
    }
}
