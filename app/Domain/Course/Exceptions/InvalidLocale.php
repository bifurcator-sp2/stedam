<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use InvalidArgumentException;

/**
 * Некорректный язык курса: не формат xx / xx-XX или длиннее 8 символов.
 *
 * Отдельный тип (а не голый InvalidArgumentException), чтобы HTTP-слой
 * отвечал 422, а не 500.
 */
final class InvalidLocale extends InvalidArgumentException {}
