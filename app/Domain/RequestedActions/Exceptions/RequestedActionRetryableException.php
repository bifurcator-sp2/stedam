<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

/**
 * Транзиентная инфраструктурная ошибка (сеть, Redis, SMTP, очередь).
 *
 * ErrorCollector: агрегат → error, ActionError эмитится; в воркере
 * допускается retry по $tries/$backoff; после исчерпания — error.
 */
class RequestedActionRetryableException extends \RuntimeException {}
