<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Probability;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Вероятность.
 *
 * difficulty:
 *   0.0–0.35  — классическое определение на кубике (P = m/n)
 *   0.35–0.65 — на монетах / картах
 *   0.65–1.00 — независимые события (P(A·B) = P(A)·P(B))
 *
 * Все ответы — несократимые дроби в формате «m/n».
 */
final class ProbabilityGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.probability.probability.probability';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.35 => $this->die($difficulty),
            $difficulty < 0.65 => $this->coins($difficulty),
            default            => $this->independent($difficulty),
        };
    }

    // ---------------------------------------------------------- кубик

    private function die(float $difficulty): MathProblem
    {
        // Событие: выпадение числа из заданного множества.
        $targets = $this->pick([
            [2],                         // P = 1/6
            [1, 3, 5],                   // P = 1/2 — нечётное
            [2, 4, 6],                   // P = 1/2 — чётное
            [1, 2, 3],                   // P = 1/2
            [4, 5, 6],                   // P = 1/2
            [1, 2, 3, 4],                // P = 2/3
            [3],                         // P = 1/6
        ]);
        $targetsStr = implode(',\\; ', $targets);
        $m = count($targets);
        $n = 6;
        [$num, $den] = self::reduce($m, $n);

        $solution = "**Решение:**\n\n"
            . "При броске кубика возможны 6 равновероятных исходов.\n\n"
            . "Благоприятных исходов: $m (числа $targetsStr).\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P = \\dfrac{m_{\\text{благ.}}}{n_{\\text{всего}}}",
                "P = \\dfrac{$m}{$n}",
                "P = \\dfrac{$num}{$den}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$num}{$den}");

        return new MathProblem(
            question: "Бросают игральный кубик. Найдите вероятность того, что выпадет число $targetsStr.",
            solution: $solution,
            answer: "$num/$den",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    // ---------------------------------------------------------- монеты

    private function coins(float $difficulty): MathProblem
    {
        $count = $this->rnd(2, 4);
        $total = 2 ** $count;

        // Событие: ровно k орлов.
        $k = $this->rnd(1, $count - 1);
        $favor = self::combinations($count, $k);

        [$num, $den] = self::reduce($favor, $total);

        $solution = "**Решение:**\n\n"
            . "При броске $count монет всего $total равновероятных исходов.\n\n"
            . "Число исходов «ровно $k орла(-ов)» — это $favor:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "C_{$count}^{$k} = \\dfrac{{$count}!}{{$k}! \\cdot (" . ($count - $k) . ")!}",
                "C_{$count}^{$k} = $favor",
            ]))
            . "\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P = \\dfrac{$favor}{$total}",
                "P = \\dfrac{$num}{$den}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$num}{$den}");

        return new MathProblem(
            question: "Монету бросают $count раза(-). Какова вероятность, что ровно $k раз(-) выпадет орёл?",
            solution: $solution,
            answer: "$num/$den",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    // ---------------------------------------------------------- независимые

    private function independent(float $difficulty): MathProblem
    {
        // Два независимых события с вероятностями p1 и p2.
        [$n1, $d1] = $this->pick([[1, 2], [1, 3], [1, 4], [2, 5], [3, 5], [1, 6], [5, 6]]);
        [$n2, $d2] = $this->pick([[1, 2], [1, 3], [1, 4], [2, 5], [3, 5], [1, 6], [5, 6]]);

        [$num, $den] = self::reduce($n1 * $n2, $d1 * $d2);

        $solution = "**Решение:**\n\n"
            . "Для независимых событий вероятность одновременного "
            . "наступления равна произведению вероятностей:\n\n"
            . KaTeX::display('P(A \cap B) = P(A) \cdot P(B)')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "P(A \cap B) &= \\dfrac{$n1}{$d1} \\cdot \\dfrac{$n2}{$d2} \\\\",
                "  &= \\dfrac{" . ($n1 * $n2) . "}{" . ($d1 * $d2) . "} \\\\",
                "  &= \\dfrac{$num}{$den}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$num}{$den}");

        return new MathProblem(
            question: "Вероятность события A равна \\(\\dfrac{$n1}{$d1}\\), "
                . "события B — \\(\\dfrac{$n2}{$d2}\\). "
                . "События независимы. Найдите \\(P(A \\cap B)\\).",
            solution: $solution,
            answer: "$num/$den",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    // ---------------------------------------------------------- помощники

    /**
     * @return array{0:int,1:int} несократимая пара (числитель, знаменатель)
     */
    private static function reduce(int $n, int $d): array
    {
        $g = self::gcd($n, $d);
        return [intdiv($n, $g), intdiv($d, $g)];
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return $a === 0 ? 1 : $a;
    }

    private static function combinations(int $n, int $k): int
    {
        if ($k > $n - $k) {
            $k = $n - $k;
        }
        $r = 1;
        for ($i = 0; $i < $k; $i++) {
            $r = intdiv($r * ($n - $i), $i + 1);
        }
        return $r;
    }
}
