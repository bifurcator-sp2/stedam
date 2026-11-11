<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Arithmetic;

/**
 * Лист дерева выражения — числовой литерал.
 *
 * Является атомом: не требует скобок ни при каком родителе,
 * поэтому precedence() возвращает PHP_INT_MAX.
 */
final class NumberNode extends ExprNode
{
    public function __construct(
        public readonly int|float $value,
    ) {}

public function toLatex(): string
{
    return self::renderNumber($this->value);
}

public function evaluate(): int|float
{
    return $this->value;
}

public function precedence(): int
{
    return PHP_INT_MAX;
}

/**
 * Отрисовка числа для LaTeX.
 *
 * - int  → как есть;
 * - float с нулевой дробной частью → как int (чтобы не было 12.0 в рендере);
 * - float с дробной частью → с точкой как десятичным разделителем
 *   (LaTeX ожидает точку, не запятую), хвостовые нули обрезаются.
 */
private static function renderNumber(int|float $value): string
{
    if (is_int($value)) {
        return (string) $value;
    }

    if (floor($value) === $value) {
        return (string) (int) $value;
    }

    $formatted = number_format($value, 6, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');

    return $formatted === '' ? '0' : $formatted;
}
}
