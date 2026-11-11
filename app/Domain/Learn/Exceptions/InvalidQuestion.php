<?php

declare(strict_types=1);

namespace App\Domain\Learn\Exceptions;

use InvalidArgumentException;

/**
 * Некорректное сгенерированное задание.
 *
 * Нарушен инвариант QuestionItem / LearnQuestions (индекс < 1, пустой текст,
 * difficulty вне 0..1, дубль индекса).
 */
final class InvalidQuestion extends InvalidArgumentException
{
}
