<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\Algebra\Inequalities;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

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
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.4 => $this->simple($difficulty, $rng),
            $difficulty < 0.7 => $this->withOffset($difficulty, $rng),
            default           => $this->withNegative($difficulty, $rng),
        };
    }

    private function simple(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 9);
        $k = $this->rnd($rng, 1, 12);          // граница решения: x < k
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

    private function withOffset(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 9);
        $b = $this->rnd($rng, 1, 20);
        $k = $this->rnd($rng, 1, 10);          // x ≤ k
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
            answer: "x ≤ $k",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function withNegative(float $difficulty, Randomizer $rng): MathProblem
    {
        // −ax + b < c  ⇔  −ax < c − b  ⇔  x > (b − c)/a. Пусть x > k, тогда b − c = a·k.
        $a = $this->rnd($rng, 2, 9);
        $b = $this->rnd($rng, 10, 30);
        $k = $this->rnd($rng, 1, 8);           // x > k
        $c = $b - $a * $k;                     // b − c = a·k

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
