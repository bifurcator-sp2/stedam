<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\Ports\SnapshotResolverInterface;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\SnapshotHash;

final class FakeSnapshotResolver implements SnapshotResolverInterface
{
    public int $calls = 0;

    public int $forceCalls = 0;

    public function ensureFor(LessonId $lessonId): SnapshotHash
    {
        $this->calls++;

        return SnapshotHash::fromContent("lesson-{$lessonId->value}");
    }

    public function forceEnsureFor(LessonId $lessonId): SnapshotHash
    {
        $this->forceCalls++;

        return $this->ensureFor($lessonId);
    }

    public function lessonFor(SnapshotHash $hash): LessonId
    {
        return new LessonId(1);
    }
}
