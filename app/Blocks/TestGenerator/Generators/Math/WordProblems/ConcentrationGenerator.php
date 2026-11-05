<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\WordProblems;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Задачи на концентрацию / растворы.
 *
 * difficulty:
 *   0.0–0.5  — масса вещества: m_в = m_р · c / 100
 *   0.5–1.0  — смешивание двух растворов одной концентрации
 *
 * Везде подбираем числа так, чтобы ответ был целым.
 */
final class ConcentrationGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.word_problems.concentration.concentration';
    }

    protected function build(float $difficulty): MathProblem
    {
        return $difficulty < 0.5
            ? $this->massOfSolute($difficulty)
            : $this->mixTwo($difficulty);
    }

    private function massOfSolute(float $difficulty): MathProblem
    {
        // Концентрация кратна 5, чтобы произведение было целым.
        $c = $this->pick([5, 10, 15, 20, 25, 40, 50]);
        $denominator = 100 / self::gcd($c, 100);       // чтобы m_р · c/100 было целым
        $mSolution = $this->rnd(2, 20) * $denominator;

        $mSolute = intdiv($mSolution * $c, 100);

        $solution = "**Решение:**\n\n"
            . "Масса раствора \\(m_{\\text{р}} = $mSolution\\) г, "
            . "концентрация \\(c = $c\\%\\).\n\n"
            . "Масса растворённого вещества:\n\n"
            . KaTeX::display('m_{\text{в}} = m_{\text{р}} \cdot \dfrac{c}{100}')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "m_{\\text{в}} = $mSolution \\cdot \\dfrac{$c}{100}",
                "m_{\\text{в}} = $mSolute \\text{ г}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$mSolute \\text{ г}");

        return new MathProblem(
            question: "Имеется \\($mSolution\\) г раствора с концентрацией \\($c\\%\\). "
                . "Сколько граммов вещества в нём растворено?",
            solution: $solution,
            answer: (string) $mSolute,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function mixTwo(float $difficulty): MathProblem
    {
        // Смешиваем два раствора одинаковой концентрации c.
        // Считаем массу вещества в смеси.
        $c = $this->pick([5, 10, 15, 20, 25, 40, 50]);
        $denominator = 100 / self::gcd($c, 100);

        $m1 = $this->rnd(2, 15) * $denominator;
        $m2 = $this->rnd(2, 15) * $denominator;

        $mTotal = $m1 + $m2;
        $mSolute = intdiv($mTotal * $c, 100);

        $solution = "**Решение:**\n\n"
            . "Смешали два раствора одинаковой концентрации \\($c\\%\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "m_1 = $m1 \\text{ г}, & \\quad m_2 = $m2 \\text{ г} \\\\",
                "m_{\\text{смеси}} = m_1 + m_2 = $mTotal \\text{ г}",
            ]))
            . "\n\nМасса вещества в смеси:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "m_{\\text{в}} = $mTotal \\cdot \\dfrac{$c}{100}",
                "m_{\\text{в}} = $mSolute \\text{ г}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$mSolute \\text{ г}");

        return new MathProblem(
            question: "Смешали \\($m1\\) г и \\($m2\\) г растворов с концентрацией \\($c\\%\\). "
                . "Сколько граммов вещества окажется в смеси?",
            solution: $solution,
            answer: (string) $mSolute,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return $a === 0 ? 1 : $a;
    }
}
