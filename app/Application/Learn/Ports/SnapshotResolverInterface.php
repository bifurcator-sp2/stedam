<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Domain\Learn\Exceptions\SnapshotMissing;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Domain\Learn\ValueObjects\SnapshotHash;

/**
 * Требовательное получение снапшота: в отличие от ensureFor() —
 * не доверяет кэшу и проверяет, что содержимое реально лежит в хранилище.
 *
 * Нужно для самовосстановления: если файл снапшота удалён, а хэш жив
 * в Redis, обычный ensureFor() вернёт «висячий» адрес. forceEnsureFor()
 * при отсутствии файла пересобирает снапшот под distributed lock.
 */
interface SnapshotResolverInterface
{
    /**
     * Возвращает хэш существующего снапшота или создаёт новый.
     * Внутри — Redis-кэш, S3, distributed lock. Для Action — чёрный ящик.
     */
    public function ensureFor(LessonId $lessonId): SnapshotHash;

    /**
     * Как ensureFor(), но файл снапшота обязан существовать в хранилище:
     * при его отсутствии содержимое пересобирается и записывается заново.
     */
    public function forceEnsureFor(LessonId $lessonId): SnapshotHash;

    /**
     * Находит урок, которому принадлежит снапшот с данным хэшем.
     * Нужно для восстановления удалённого файла по уже зафиксированному
     * в прохождении хэшу.
     *
     * @throws SnapshotMissing если живого урока с таким снапшотом нет
     */
    public function lessonFor(SnapshotHash $hash): LessonId;
}
