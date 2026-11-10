<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\Context\ContextResolverInterface;
use App\Application\Learn\Context\ViewerContext;
use App\Domain\Learn\ValueObjects\UserId;

/**
 * Резолвер контекста с заранее заданной картой userId → ViewerContext.
 */
final class StubContextResolver implements ContextResolverInterface
{
    /** @param array<int, ViewerContext> $map */
    public function __construct(private array $map) {}

    public function resolve(UserId $userId): ViewerContext
    {
        return $this->map[$userId->value]
            ?? ViewerContext::readOnly($userId->value, [], [$userId->value]);
    }
}
