<?php

declare(strict_types=1);

namespace App\Application\Learn\Exceptions;

/**
 * Действие/чтение запрещено текущим контекстом просмотра
 * (вне scope или нет соответствующей capability у роли).
 */
final class LearnAccessDenied extends \RuntimeException {}
