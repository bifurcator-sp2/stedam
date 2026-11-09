<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Algebra\Progressions;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Прогрессии.
 *
 * difficulty:
 *   0.0–0.4  — арифметическая: n-й член aₙ = a₁ + (n−1)d
 *   0.4–0.7  — арифметическая: сумма Sₙ = (a₁ + aₙ)/2 · n
 *   0.7–1.0  — геометрическая: n-й член bₙ = b₁ · q^(n−1)
 */
final class ProgressionsGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.4 => $this->arithmeticTerm($difficulty, $rng),
            $difficulty < 0.7 => $this->arithmeticSum($difficulty, $rng),
            default           => $this->geometricTerm($difficulty, $rng),
        };
    }

    private function arithmeticTerm(float $difficulty, Randomizer $rng): MathProblem
    {
        $a1 = $this->rnd($rng, 1, 10);
        $d  = $this->rnd($rng, 2, 8);
        $n  = $this->rnd($rng, 5, 15);
        $an = $a1 + ($n - 1) * $d;
        $nMinus1 = $n - 1;
        $prod = $nMinus1 * $d;

        $solution = "**Решение:**\n\n"
            . "Формула n-го члена арифметической прогрессии:\n\n"
            . KaTeX::display("a_n = a_1 + (n - 1)d")
            . "\n\nПодставим \\(a_1 = $a1\\), \\(d = $d\\), \\(n = $n\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "a_{$n} &= $a1 + ($n - 1) \\cdot $d \\\\",
                "&= $a1 + $prod \\\\",
                "&= $an",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("a_{$n} = $an");

        return new MathProblem(
            question: "В арифметической прогрессии \\(a_1 = $a1\\), \\(d = $d\\). "
            . "Найдите \\(a_{$n}\\).",
            solution: $solution,
            answer: (string) $an,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function arithmeticSum(float $difficulty, Randomizer $rng): MathProblem
    {
        $a1 = $this->rnd($rng, 1, 8);
        $d  = $this->rnd($rng, 2, 5);
        $n  = $this->rnd($rng, 6, 12);
        $an = $a1 + ($n - 1) * $d;
        $sumPairs = $a1 + $an;
        $product = $sumPairs * $n;
        $sn = intdiv($product, 2);

        $solution = "**Решение:**\n\n"
            . "Сначала найдём \\(a_{$n}\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "a_{$n} &= $a1 + ($n - 1) \\cdot $d = $an",
            ]))
            . "\n\nСумма первых $n членов:\n\n"
            . KaTeX::display("S_n = \\dfrac{a_1 + a_n}{2} \\cdot n")
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "S_{$n} &= \\dfrac{{$a1} + {$an}}{2} \\cdot $n \\\\",
                "&= \\dfrac{$sumPairs}{2} \\cdot $n \\\\",
                "&= $sn",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("S_{$n} = $sn");

        return new MathProblem(
            question: "В арифметической прогрессии \\(a_1 = $a1\\), \\(d = $d\\). "
            . "Найдите сумму первых $n членов.",
            solution: $solution,
            answer: (string) $sn,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function geometricTerm(float $difficulty, Randomizer $rng): MathProblem
    {
        $b1 = $this->rnd($rng, 1, 3);
        $q  = $this->rnd($rng, 2, 3);
        $n  = $this->rnd($rng, 3, 5);
        $bn = $b1 * ($q ** ($n - 1));
        $nMinus1 = $n - 1;

        $solution = "**Решение:**\n\n"
            . "Формула n-го члена геометрической прогрессии:\n\n"
            . KaTeX::display("b_n = b_1 \\cdot q^{\\,n - 1}")
            . "\n\nПодставим \\(b_1 = $b1\\), \\(q = $q\\), \\(n = $n\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "b_{$n} &= $b1 \\cdot {$q}^{$nMinus1} \\\\",
                "&= $bn",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("b_{$n} = $bn");

        return new MathProblem(
            question: "В геометрической прогрессии \\(b_1 = $b1\\), \\(q = $q\\). "
            . "Найдите \\(b_{$n}\\).",
            solution: $solution,
            answer: (string) $bn,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
