<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Domain\Learn\ValueObjects\LessonId;

/**
 * Сборка сериализуемого payload для хэша снапшота урока.
 *
 * ВАЖНО (спека Learn §8.3, §14.17): содержательная часть генеративных
 * (proc/test) заданий ИСКЛЮЧАЕТСЯ из снапшота. Proc-узлы сериализуются
 * с пустым default — чтобы хэш снапшота был одинаковым для всех учеников,
 * стартовавших один и тот же (неизменённый) урок, и не зависел от
 * случайности генератора.
 *
 * Статические блоки входят в снапшот как есть.
 *
 * Контракт детерминизма: при одинаковой структуре урока assemble() обязан
 * вернуть одинаковый массив (порядок ключей нормализуется на слое
 * S3SnapshotResolver через canonicalJson).
 */
interface LessonSnapshotAssemblerInterface
{
    /**
     * @return array<string, mixed>
     * @throws \App\Application\Learn\Exceptions\LessonNotFound
     */
    public function assemble(LessonId $lessonId): array;
}
