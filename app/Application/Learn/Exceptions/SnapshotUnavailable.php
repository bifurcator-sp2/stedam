<?php

declare(strict_types=1);

namespace App\Application\Learn\Exceptions;

/**
 * Снапшот прохождения не зафиксирован (created/scheduled — до старта)
 * либо его содержимое недоступно в хранилище.
 */
final class SnapshotUnavailable extends \RuntimeException {}
