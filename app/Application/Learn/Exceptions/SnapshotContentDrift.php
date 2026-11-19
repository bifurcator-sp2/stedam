<?php

declare(strict_types=1);

namespace App\Application\Learn\Exceptions;

use App\Domain\Learn\ValueObjects\SnapshotHash;

/**
 * Содержимое урока изменилось: пересобранный снапшот не совпадает
 * с хэшем, зафиксированным в прохождении.
 *
 * Восстановление удалённого файла допустимо только для неизменного урока —
 * иначе ученик увидел бы не ту версию, на которой начал учиться.
 */
final class SnapshotContentDrift extends \RuntimeException
{
    public function __construct(
        public readonly SnapshotHash $expected,
        public readonly SnapshotHash $actual,
    ) {
        parent::__construct(sprintf(
            'Snapshot %s cannot be restored: lesson content now hashes to %s',
            $expected->value,
            $actual->value,
        ));
    }
}
