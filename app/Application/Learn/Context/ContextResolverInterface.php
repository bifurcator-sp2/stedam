<?php

declare(strict_types=1);

namespace App\Application\Learn\Context;

use App\Domain\Learn\ValueObjects\UserId;

/**
 * Порт: собрать ViewerContext для пользователя.
 *
 * Реализация (Infrastructure) знает про Spatie-роли и граф user_relations;
 * Application лишь получает готовый контекст. Domain о контексте не знает.
 */
interface ContextResolverInterface
{
    public function resolve(UserId $userId): ViewerContext;
}
