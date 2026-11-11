<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Generators\Math\Algebra\Equations;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Infrastructure\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Infrastructure\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Квадратные уравнения.
 *
 * difficulty:
 *   0.0–0.3 — неполное: x² = c (два корня ±√c)
 *   0.3–0.6 — неполное: x² + bx = 0 (x(x + b) = 0)
 *   0.6–1.0 — полное: x² + px + q = 0 (корни целые, теорема Виета)
 */
final class QuadraticEquationGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.3 => $this->simple($difficulty, $rng),
            $difficulty < 0.6 => $this->missingConstant($difficulty, $rng),
            default           => $this->full($difficulty, $rng),
        };
    }

    private function simple(float $difficulty, Randomizer $rng): MathProblem
    {
        $root = $this->rnd($rng, 2, 12);
        $c = $root * $root;

        $solution = "**Решение:**\n\n"
            . "Извлекаем квадратный корень из обеих частей:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&x^{2} = $c \\\\",
                "&x = \\pm\\sqrt{$c} \\\\",
                "&x = \\pm {$root}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x_{1} = {$root},\\; x_{2} = -{$root}");

        return new MathProblem(
            question: "Решите уравнение: " . KaTeX::inline("x^{2} = $c"),
            solution: $solution,
            answer: "x = ±{$root}",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function missingConstant(float $difficulty, Randomizer $rng): MathProblem
    {
        $b = $this->rnd($rng, 1, 12);
        $root2 = -$b;

        $solution = "**Решение:**\n\n"
            . "Вынесем \\(x\\) за скобки:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&x^{2} + {$b}x = 0 \\\\",
                "&x(x + {$b}) = 0 \\\\",
                "&x = 0 \\quad\\text{или}\\quad x = $root2",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x_{1} = 0,\\; x_{2} = $root2");

        return new MathProblem(
            question: "Решите уравнение: " . KaTeX::inline("x^{2} + {$b}x = 0"),
            solution: $solution,
            answer: "x = 0, x = $root2",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function full(float $difficulty, Randomizer $rng): MathProblem
    {
        $r1 = $this->rnd($rng, 1, 8);
        $r2 = $this->rnd($rng, 1, 8);
        while ($r1 === $r2) {
            $r2 = $this->rnd($rng, 1, 8);
        }
        $sum = $r1 + $r2;
        $product = $r1 * $r2;
        $p = -$sum;
        $q = $product;

        $signP = $p < 0 ? '-' : '+';
        $absP  = abs($p);

        $solution = "**Решение:**\n\n"
            . "По теореме Виета:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "x_{1} + x_{2} &= " . (-$p) . " \\\\",
                "x_{1} \\cdot x_{2} &= $q",
            ]))
            . "\n\nПодходят числа \\($r1\\) и \\($r2\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$r1 + $r2 = $sum, \\quad $r1 \\cdot $r2 = $product",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x_{1} = $r1,\\; x_{2} = $r2");

        return new MathProblem(
            question: "Решите уравнение: " . KaTeX::inline("x^{2} $signP {$absP}x + {$q} = 0"),
            solution: $solution,
            answer: "x = $r1, x = $r2",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
