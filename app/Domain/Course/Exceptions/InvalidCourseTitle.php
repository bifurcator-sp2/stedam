<?php

declare(strict_types=1);

namespace App\Domain\Course\Exceptions;

use InvalidArgumentException;

/**
 * Некорректное название курса/темы (пустое или длиннее 255 символов).
 *
 * Наследует InvalidArgumentException ради совместимости с кодом, который
 * ловил generic-исключение, но имеет собственный тип: HTTP-слой без него
 * отдавал бы 500 вместо 422.
 */
final class InvalidCourseTitle extends InvalidArgumentException {}
