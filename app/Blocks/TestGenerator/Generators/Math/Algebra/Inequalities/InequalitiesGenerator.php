<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Algebra\Inequalities;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Неравенства.
 *
 * difficulty:
 *   0.0–0.4  — ax < c (a > 0, деление на положительное)
 *   0.4–0.7  — ax + b ≤ c (перенос + деление)
 *   0.7–1.0  — −ax + b < c (деление на отрицательное, знак меняется)
 */
final class InequalitiesGenerator extends GeneratorBase
{
    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.4 => $this->simple($difficulty),
            $difficulty < 0.7 => $this->withOffset($difficulty),
            default           => $this->withNegative($difficulty),
        };
    }

    private function simple(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 9);
        $k = $this->rnd(1, 12);          // граница решения: x < k
        $c = $a * $k;

        $solution = "**Решение:**\n\n"
            . "Разделим обе части на \\($a > 0\\) — знак неравенства не меняется:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&{$a}x < {$c} \\\\",
                "&x < \\dfrac{$c}{$a} \\\\",
                "&x < $k",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x < $k");

        return new MathProblem(
            question: "Решите неравенство: " . KaTeX::inline("{$a}x < {$c}"),
            solution: $solution,
            answer: "x < $k",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function withOffset(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 9);
        $b = $this->rnd(1, 20);
        $k = $this->rnd(1, 10);          // x в‰¤ k
        $c = $a * $k + $b;

        $solution = "**Решение:**\n\n"
            . "Перенесём \\($b\\) вправо, затем разделим на \\($a > 0\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&{$a}x + {$b} \\leq {$c} \\\\",
                "&{$a}x \\leq " . ($c - $b) . " \\\\",
                "&x \\leq $k",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x \\leq $k");

        return new MathProblem(
            question: "Решите неравенство: " . KaTeX::inline("{$a}x + {$b} \\leq {$c}"),
            solution: $solution,
            answer: "x в‰¤ $k",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function withNegative(float $difficulty): MathProblem
    {
        // -ax + b < c  ⇔  -ax < c - b  ⇔  x > (b - c)/a.  Пусть x > k, тогда b - c = a·k.
        $a = $this->rnd(2, 9);
        $b = $this->rnd(10, 30);
        $k = $this->rnd(1, 8);           // x > k
        $c = $b - $a * $k;               // b - c = aВ·k

        $solution = "**Решение:**\n\n"
            . "Перенесём \\($b\\) вправо, затем разделим на \\(-$a < 0\\) — "
            . "знак неравенства меняется на противоположный:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&-{$a}x + {$b} < {$c} \\\\",
                "&-{$a}x < " . ($c - $b) . " \\\\",
                "&x > \\dfrac{" . ($b - $c) . "}{$a} \\\\",
                "&x > $k",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x > $k");

        return new MathProblem(
            question: "Решите неравенство: " . KaTeX::inline("-{$a}x + {$b} < {$c}"),
            solution: $solution,
            answer: "x > $k",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
