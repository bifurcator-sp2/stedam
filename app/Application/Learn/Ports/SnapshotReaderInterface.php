<?php

declare(strict_types=1);

namespace App\Application\Learn\Ports;

use App\Domain\Learn\ValueObjects\SnapshotHash;

/**
 * Чтение содержимого уже зафиксированного снапшота.
 *
 * В отличие от SnapshotResolverInterface, ничего не строит и не пишет:
 * берёт ровно ту версию, на которую ссылается прохождение (learns.lesson_snapshot).
 */
interface SnapshotReaderInterface
{
    /**
     * JSON снапшота по его хэшу (как он был сохранён: canonical JSON).
     */
    public function read(SnapshotHash $hash): string;
}
