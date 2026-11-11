<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Support;

/**
 * Утилиты для сборки строк с KaTeX-разметкой.
 *
 * Формат, который использует весь модуль:
 *  - inline-формулы оборачиваются в \\( ... \\);
 *  - блочные (display) — в \\[ ... \\].
 *
 * Такой выбор делимитеров:
 *  - совместим с katex/contrib/auto-render (delimiters: '\\(...\\)', '\\[...\\]');
 *  - не конфликтует с Blade-интерполяцией $var и с JSON-ключами вида "$schema".
 */
final class KaTeX
{
    /**
     * Inline-формула: \( x + y \).
     */
    public static function inline(string $latex): string
    {
        return '\(' . trim($latex) . '\)';
    }

    /**
     * Блочная формула: \[ ... \].
     */
    public static function display(string $latex): string
    {
        return '\[' . trim($latex) . '\]';
    }

    /**
     * Экранирует LaTeX-спецсимволы в текстовой части.
     *
     * Полезно, если подставляете пользовательские строки внутрь math-режима
     * (например, единицы измерения или имена переменных).
     */
    public static function escapeText(string $text): string
    {
        return preg_replace('/([\\\\{}$%&#^_])/', '\\\\$1', $text) ?? $text;
    }

    /**
     * Собирает пошаговое решение в виде aligned-окружения.
     *
     * Первый шаг выравнивается по левому краю без префикса "=",
     * все последующие — с префиксом "&= " (классическая запись
     * цепочки преобразований).
     *
     * @param string[] $steps LaTeX-выражения (без знака "=", без "&").
     */
    public static function aligned(array $steps): string
    {
        if ($steps === []) {
            return '';
        }

        $lines = ['&' . array_shift($steps)];
        foreach ($steps as $step) {
            $lines[] = '&= ' . $step;
        }

        return '\begin{aligned}' . implode(' \\\\ ', $lines) . '\end{aligned}';
    }

    /**
     * Собирает многострочное выравнивание с произвольными префиксами.
     *
     * Полезно для случаев, когда шаги не являются цепочкой "= ...",
     * а, например, системой или подстановкой формулы:
     *
     *   KaTeX::alignedRaw(['S &= a \cdot b', '  &= 3 \cdot 4', '  &= 12'])
     *
     * @param string[] $lines Полные строки с "\&=" и прочими выравниваниями.
     */
    public static function alignedRaw(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        return '\begin{aligned}' . implode(' \\\\ ', $lines) . '\end{aligned}';
    }

    /**
     * Форматирует число для отображения: убирает хвостовые нули,
     * использует запятую как десятичный разделитель не нужен —
     * KaTeX/LaTeX ожидает точку.
     */
    public static function number(int|float $value, int $precision = 2): string
    {
        if (is_int($value) || floor((float) $value) === (float) $value) {
            return (string) (int) $value;
        }

        $formatted = number_format((float) $value, $precision, '.', '');
        // Убираем хвостовые нули и возможную точку.
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
