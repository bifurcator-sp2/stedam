<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Numbers;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Сравнение двух натуральных чисел: <, > или =.
 */
final class NumberComparisonGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.numbers.comparison';
    }

    protected function build(float $difficulty): MathProblem
    {
        $max = (int) round(10 + $difficulty * 9990);
        $a   = $this->rnd(1, $max);
        $b   = $this->rnd(1, $max);
        if ($difficulty > 0.5 && random_int(1, 100) <= 20) {
            $b = $a;
        }

        $sign = $a <=> $b;
        $symbol = match ($sign) { 1 => '>', -1 => '<', default => '=' };

        $solution = "**Решение:**\n\n"
            . "Сравним числа по разрядам (сначала старшие).\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$a \\quad ? \\quad $b",
                "$a \\; $symbol \\; $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$a $symbol $b");

        return new MathProblem(
            question: "Сравните числа: \\($a\\) и \\($b\\). Поставьте знак \\(<\\), \\(>\\) или \\(=\\).",
            solution: $solution,
            answer: $symbol,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
