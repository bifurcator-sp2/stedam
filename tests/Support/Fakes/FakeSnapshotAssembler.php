<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Application\Learn\Ports\LessonSnapshotAssemblerInterface;
use App\Domain\Learn\ValueObjects\LessonId;

/**
 * Фейковый ассемблер снапшота урока: отдаёт заранее заданный payload
 * по lessonId и считает, сколько раз его вызвали.
 */
final class FakeSnapshotAssembler implements LessonSnapshotAssemblerInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $payloads;

    public int $calls = 0;

    /**
     * @param array<int, array<string, mixed>> $payloads
     */
    public function __construct(array $payloads = [])
    {
        $this->payloads = $payloads;
    }

    public function assemble(LessonId $lessonId): array
    {
        $this->calls++;

        return $this->payloads[$lessonId->value] ?? ['id' => $lessonId->value, 'title' => 'L'];
    }
}
