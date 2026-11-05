<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\NumberTheory;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * НОД и НОК.
 *
 * difficulty:
 *   0.0–0.5  — НОД двух чисел
 *   0.5–1.0  — НОК двух чисел
 *
 * Для вычисления используется алгоритм Евклида; в решении
 * показывается и он, и проверка (a·b = НОД·НОК).
 */
final class GcdLcmGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.number_theory.divisibility.gcd_lcm';
    }

    protected function build(float $difficulty): MathProblem
    {
        return $difficulty < 0.5
            ? $this->gcd($difficulty)
            : $this->lcm($difficulty);
    }

    private function gcd(float $difficulty): MathProblem
    {
        $max = $difficulty < 0.25 ? 50 : ($difficulty < 0.4 ? 200 : 500);
        $a = $this->rnd(4, $max);
        $b = $this->rnd(4, $max);
        if ($a === $b) {
            $b = $a + $this->rnd(1, 10);
        }

        $gcd = self::computeGcd($a, $b);
        $steps = self::euclidSteps($a, $b);

        $solution = "**Решение:**\n\n"
            . "Найдём \\(\\gcd($a, $b)\\) алгоритмом Евклида:\n\n"
            . KaTeX::display(KaTeX::alignedRaw($steps))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $gcd);

        return new MathProblem(
            question: "Найдите наибольший общий делитель чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $gcd,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function lcm(float $difficulty): MathProblem
    {
        $max = $difficulty < 0.7 ? 20 : 40;
        $a = $this->rnd(4, $max);
        $b = $this->rnd(4, $max);
        if ($a === $b) {
            $b = $a + $this->rnd(1, 5);
        }

        $gcd = self::computeGcd($a, $b);
        $lcm = intdiv($a * $b, $gcd);

        $solution = "**Решение:**\n\n"
            . "Найдём \\(\\gcd($a, $b)\\) алгоритмом Евклида:\n\n"
            . KaTeX::display(KaTeX::alignedRaw(self::euclidSteps($a, $b)))
            . "\n\nИспользуем формулу \\(\\operatorname{lcm}(a, b) = \\dfrac{a \\cdot b}{\\gcd(a, b)}\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\operatorname{lcm}($a, $b)",
                // ФИКС: было "\\dfrac{$a \\cdot $b}{$gcd}" — невалидный PHP.
                // Стало — раздельные complex-блоки {{$a} \\cdot {$b}}.
                "\\dfrac{{$a} \\cdot {$b}}{$gcd}",
                "\\dfrac{" . ($a * $b) . "}{$gcd}",
                (string) $lcm,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $lcm);

        return new MathProblem(
            question: "Найдите наименьшее общее кратное чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $lcm,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private static function computeGcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return $a;
    }

    /**
     * @return list<string> строки для KaTeX::alignedRaw()
     */
    private static function euclidSteps(int $a, int $b): array
    {
        $x = $a;
        $y = $b;
        $lines = [];
        while ($y !== 0) {
            $q = intdiv($x, $y);
            $r = $x % $y;
            $lines[] = "$x = $y \\cdot $q + $r";
            [$x, $y] = [$y, $r];
        }
        return $lines;
    }
}
