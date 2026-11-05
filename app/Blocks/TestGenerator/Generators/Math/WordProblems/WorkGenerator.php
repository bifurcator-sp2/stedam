<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\WordProblems;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Задачи на работу.
 *
 * difficulty:
 *   0.0–0.35  — объём: A = p · t
 *   0.35–0.55 — производительность: p = A / t
 *   0.55–0.75 — время: t = A / p
 *   0.75–1.00 — совместная работа: A = (p₁ + p₂) · t
 *
 * Производительности и времена целые; A получается целым.
 */
final class WorkGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.word_problems.work.work';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.35 => $this->volume($difficulty),
            $difficulty < 0.55 => $this->rate($difficulty),
            $difficulty < 0.75 => $this->time($difficulty),
            default            => $this->joint($difficulty),
        };
    }

    private function volume(float $difficulty): MathProblem
    {
        $p = $this->rnd(2, 20);
        $t = $this->rnd(2, 8);
        $a = $p * $t;

        $solution = "**Решение:**\n\n"
            . "Производительность \\(p = $p\\) деталей в час, время \\(t = $t\\) ч.\n\n"
            . "Объём работы:\n\n"
            . KaTeX::display('A = p \cdot t')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "A = $p \\cdot $t",
                "A = $a",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $a);

        return new MathProblem(
            question: "Рабочий делает \\($p\\) деталей в час. Сколько деталей он изготовит за \\($t\\) ч?",
            solution: $solution,
            answer: (string) $a,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function rate(float $difficulty): MathProblem
    {
        $p = $this->rnd(3, 25);
        $t = $this->rnd(2, 7);
        $a = $p * $t;

        $solution = "**Решение:**\n\n"
            . "Объём работы \\(A = $a\\), время \\(t = $t\\) ч.\n\n"
            . "Производительность:\n\n"
            . KaTeX::display('p = \dfrac{A}{t}')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "p = \\dfrac{$a}{$t}",
                "p = $p \\text{ дет./ч}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$p \\text{ дет./ч}");

        return new MathProblem(
            question: "Мастер изготовил \\($a\\) деталей за \\($t\\) ч. "
                . "Какова его производительность?",
            solution: $solution,
            answer: (string) $p,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function time(float $difficulty): MathProblem
    {
        $p = $this->rnd(3, 20);
        $t = $this->rnd(2, 8);
        $a = $p * $t;

        $solution = "**Решение:**\n\n"
            . "Объём работы \\(A = $a\\), производительность \\(p = $p\\) дет./ч.\n\n"
            . "Время:\n\n"
            . KaTeX::display('t = \dfrac{A}{p}')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "t = \\dfrac{$a}{$p}",
                "t = $t \\text{ ч}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$t \\text{ ч}");

        return new MathProblem(
            question: "Рабочему нужно изготовить \\($a\\) деталей. "
                . "Он делает \\($p\\) деталей в час. За сколько часов он справится?",
            solution: $solution,
            answer: (string) $t,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function joint(float $difficulty): MathProblem
    {
        $p1 = $this->rnd(3, 15);
        $p2 = $this->rnd(3, 15);
        while ($p1 === $p2) {
            $p2 = $this->rnd(3, 15);
        }
        $t = $this->rnd(2, 6);
        $a = ($p1 + $p2) * $t;

        $solution = "**Решение:**\n\n"
            . "Совместная производительность:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "p = p_1 + p_2",
                "p = $p1 + $p2 = " . ($p1 + $p2) . " \\text{ дет./ч}",
            ]))
            . "\n\nОбъём работы:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "A = p \\cdot t",
                "A = " . ($p1 + $p2) . " \\cdot $t",
                "A = $a",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $a);

        return new MathProblem(
            question: "Два рабочих вместе делают детали: первый — \\($p1\\) дет./ч, "
                . "второй — \\($p2\\) дет./ч. Сколько деталей они изготовят вместе за \\($t\\) ч?",
            solution: $solution,
            answer: (string) $a,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
