<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Algebra\Equations;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Линейные уравнения: ax = c, ax + b = c, a(x + b) = c.
 */
final class LinearEquationGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.algebra.equations.linear';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.35 => $this->simple($difficulty),
            $difficulty < 0.7  => $this->withOffset($difficulty),
            default            => $this->withParentheses($difficulty),
        };
    }

    private function simple(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 9);
        $x = $this->rnd(1, 15);
        $c = $a * $x;

        $solution = "**Решение:**\n\n"
            . KaTeX::display(KaTeX::aligned([
                "{$a}x = {$c}",
                "x = {$c} \\div {$a}",
                "x = {$x}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x = {$x}");

        return new MathProblem(
            question: "Решите уравнение: " . KaTeX::inline("{$a}x = {$c}"),
            solution: $solution,
            answer: "x = {$x}",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function withOffset(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 9);
        $x = $this->rnd(1, 15);
        $b = $this->rnd(1, 20);
        $c = $a * $x + $b;

        $solution = "**Решение:**\n\n"
            . KaTeX::display(KaTeX::aligned([
                "{$a}x + {$b} = {$c}",
                "{$a}x = " . ($c - $b),
                "x = " . ($c - $b) . " \\div {$a}",
                "x = {$x}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x = {$x}");

        return new MathProblem(
            question: "Решите уравнение: " . KaTeX::inline("{$a}x + {$b} = {$c}"),
            solution: $solution,
            answer: "x = {$x}",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function withParentheses(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 9);
        $b = $this->rnd(1, 10);
        $x = $this->rnd(1, 15);
        $c = $a * ($x + $b);

        $solution = "**Решение:**\n\n"
            . KaTeX::display(KaTeX::aligned([
                "{$a}\\left(x + {$b}\\right) = {$c}",
                "x + {$b} = " . ($c / $a),
                "x = " . ($c / $a - $b),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x = {$x}");

        return new MathProblem(
            question: "Решите уравнение: " . KaTeX::inline("{$a}\\left(x + {$b}\\right) = {$c}"),
            solution: $solution,
            answer: "x = {$x}",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
