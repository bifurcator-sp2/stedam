<?php

declare(strict_types=1);

namespace App\Application\RequestedActions\Actions;

use App\Application\RequestedActions\Ports\RequestedActionHandlerRegistryInterface;
use App\Domain\RequestedActions\Exceptions\RequestedActionDomainException;
use App\Domain\RequestedActions\ValueObjects\UserId;

/**
 * Read-side формы запроса: `canGetRequestForm()` + `getRequestForm()` (раздел 1).
 *
 * Не пишет в БД, транзакции нет. Ошибки доменные → 4xx
 * (маппинг — bootstrap/app.php).
 */
final readonly class GetRequestFormAction
{
    public function __construct(
        private RequestedActionHandlerRegistryInterface $handlers,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(UserId $initiator, string $handlerAlias, array $arguments): array
    {
        $handler = $this->handlers->resolve($handlerAlias);

        $arguments = ['user_id' => $initiator->value] + $arguments;

        if (! $handler->canGetRequestForm($arguments)) {
            throw new RequestedActionDomainException(
                'Request form is not available for this user'
            );
        }

        return $handler->getRequestForm($arguments);
    }
}
