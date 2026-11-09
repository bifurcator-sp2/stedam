<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\SnapshotHash;

interface SnapshotResolverInterface
{
    /**
     * Возвращает хэш существующего снапшота или создаёт новый.
     * Внутри — Redis-кэш, S3, distributed lock. Для Action — чёрный ящик.
     */
    public function ensureFor(LessonId $lessonId): SnapshotHash;
}
