<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\Algebra\Functions;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Функции.
 *
 * difficulty:
 *   0.0–0.4  — значение f(x) = kx + b в точке x₀
 *   0.4–0.7  — восстановить k и b по двум точкам (линейная)
 *   0.7–1.0  — значение f(x) = ax² + bx + c в точке x₀
 */
final class FunctionsGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.4 => $this->linearValue($difficulty, $rng),
            $difficulty < 0.7 => $this->linearFromPoints($difficulty, $rng),
            default           => $this->quadraticValue($difficulty, $rng),
        };
    }

    private function linearValue(float $difficulty, Randomizer $rng): MathProblem
    {
        $k = $this->rnd($rng, 2, 9);
        $b = $this->rnd($rng, -10, 10);
        if ($b === 0) {
            $b = 3;
        }
        $x = $this->rnd($rng, -5, 5);
        if ($x === 0) {
            $x = 2;
        }
        $product = $k * $x;
        $value = $product + $b;

        $signB = $b < 0 ? '-' : '+';
        $absB = abs($b);

        $solution = "**Решение:**\n\n"
            . "Подставим \\(x = $x\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "f($x) &= {$k} \\cdot $x $signB {$absB} \\\\",
                "&= $product $signB {$absB} \\\\",
                "&= $value",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("f($x) = $value");

        return new MathProblem(
            question: "Дана функция " . KaTeX::inline("f(x) = {$k}x $signB {$absB}")
            . ". Найдите \\(f($x)\\).",
            solution: $solution,
            answer: (string) $value,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function linearFromPoints(float $difficulty, Randomizer $rng): MathProblem
    {
        $k = $this->rnd($rng, 2, 6);
        $b = $this->rnd($rng, -5, 5);
        $x1 = $this->rnd($rng, -3, 0);
        $x2 = $this->rnd($rng, 1, 5);
        $y1 = $k * $x1 + $b;
        $y2 = $k * $x2 + $b;

        $signB = $b < 0 ? '-' : '+';
        $absB = abs($b);
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;

        $solution = "**Решение:**\n\n"
            . "Угловой коэффициент:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "k = \\dfrac{y_2 - y_1}{x_2 - x_1} "
                . "= \\dfrac{$dy}{$dx} = $k",
            ]))
            . "\n\nСвободный член найдём из точки \\(($x1; $y1)\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "{y_1} = k \\cdot {x_1} + b \\;\\Rightarrow\\; "
                . "$y1 = {$k} \\cdot ($x1) + b \\;\\Rightarrow\\; b = $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("f(x) = {$k}x $signB {$absB}");

        return new MathProblem(
            question: "Линейная функция \\(f(x) = kx + b\\) проходит через точки "
            . "\\(A($x1; $y1)\\) и \\(B($x2; $y2)\\). Найдите \\(k\\) и \\(b\\).",
            solution: $solution,
            answer: "k = $k, b = $b",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function quadraticValue(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 1, 3);
        $b = $this->rnd($rng, -4, 4);
        $c = $this->rnd($rng, -5, 5);
        $x = $this->rnd($rng, -3, 3);
        if ($x === 0) {
            $x = 2;
        }

        $x2 = $x * $x;
        $termB = $b * $x;
        $value = $a * $x2 + $termB + $c;

        $signB = $b < 0 ? '-' : '+';
        $absB = abs($b);
        $signC = $c < 0 ? '-' : '+';
        $absC = abs($c);

        $solution = "**Решение:**\n\n"
            . "Подставим \\(x = $x\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "f($x) &= {$a} \\cdot ($x)^{2} $signB {$absB} \\cdot ($x) $signC {$absC} \\\\",
                "&= {$a} \\cdot $x2 $signB $termB $signC {$absC} \\\\",
                "&= $value",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("f($x) = $value");

        return new MathProblem(
            question: "Дана функция " . KaTeX::inline(
                "f(x) = {$a}x^{2} $signB {$absB}x $signC {$absC}"
            ) . ". Найдите \\(f($x)\\).",
            solution: $solution,
            answer: (string) $value,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
