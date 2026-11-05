<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Fractions;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Дроби: сравнение / сложение с одинаковыми знаменателями /
 * сложение с разными знаменателями (в зависимости от difficulty).
 */
final class FractionsGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.fractions.fractions';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.35 => $this->compare($difficulty),
            $difficulty < 0.65 => $this->sameDenominator($difficulty),
            default            => $this->differentDenominators($difficulty),
        };
    }

    private function compare(float $difficulty): MathProblem
    {
        $b = $this->rnd(2, 9);
        $a = $this->rnd(1, $b - 1);
        $d = $this->rnd(2, 9);
        $c = $this->rnd(1, $d - 1);

        $left  = $a * $d;
        $right = $c * $b;
        $sign  = $left <=> $right;
        $symbol = match ($sign) { 1 => '>', -1 => '<', default => '=' };

        $common = $b * $d;
        $solution = "**Решение:**\n\n"
            . "Приведём к общему знаменателю \\($b \\cdot $d = $common\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a}{$b} = \\dfrac" . "{" . ($a * $d) . "}{$common}",
                "\\dfrac{$c}{$d} = \\dfrac" . "{" . ($c * $b) . "}{$common}",
            ]))
            . "\n\nЗначит, " . KaTeX::inline("\\dfrac{$a}{$b} $symbol \\dfrac{$c}{$d}")
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Сравните дроби " . KaTeX::inline("\\dfrac{$a}{$b}")
                . " и " . KaTeX::inline("\\dfrac{$c}{$d}") . ".",
            solution: $solution,
            answer: $symbol,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function sameDenominator(float $difficulty): MathProblem
    {
        $d = $this->rnd(3, 12);
        $a = $this->rnd(1, $d - 1);
        $b = $this->rnd(1, $d - $a);
        $sum = $a + $b;

        $solution = "**Решение:**\n\n"
            . "Знаменатели одинаковые — складываем числители:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a}{$d} + \\dfrac{$b}{$d}",
                "\\dfrac{$sum}{$d}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$sum}{$d}");

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("\\dfrac{$a}{$d} + \\dfrac{$b}{$d}"),
            solution: $solution,
            answer: "\\dfrac{$sum}{$d}",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function differentDenominators(float $difficulty): MathProblem
    {
        $b1 = $this->rnd(2, 9);
        $b2 = $this->rnd(2, 9);
        while ($b1 === $b2) {
            $b2 = $this->rnd(2, 9);
        }
        $a1 = $this->rnd(1, $b1 - 1);
        $a2 = $this->rnd(1, $b2 - 1);

        $common = $b1 * $b2;
        $n1 = $a1 * $b2;
        $n2 = $a2 * $b1;
        $sum = $n1 + $n2;

        $solution = "**Решение:**\n\n"
            . "Общий знаменатель: \\($common = $b1 \\cdot $b2\\).\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a1}{$b1} + \\dfrac{$a2}{$b2}",
                "\\dfrac{$n1}{$common} + \\dfrac{$n2}{$common}",
                "\\dfrac{$sum}{$common}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$sum}{$common}");

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("\\dfrac{$a1}{$b1} + \\dfrac{$a2}{$b2}"),
            solution: $solution,
            answer: "\\dfrac{$sum}{$common}",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
