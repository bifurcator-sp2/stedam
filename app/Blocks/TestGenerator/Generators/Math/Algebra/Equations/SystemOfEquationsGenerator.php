<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Algebra\Equations;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Системы линейных уравнений 2×2.
 *
 * difficulty:
 *   0.0–0.5 — метод сложения: x + y = a; x − y = b
 *   0.5–1.0 — метод подстановки: 2x + y = a; x − y = b
 */
final class SystemOfEquationsGenerator extends GeneratorBase
{
    protected function build(float $difficulty): MathProblem
    {
        return $difficulty < 0.5
            ? $this->byAddition($difficulty)
            : $this->bySubstitution($difficulty);
    }

    private function byAddition(float $difficulty): MathProblem
    {
        $x = $this->rnd(1, 10);
        $y = $this->rnd(1, 10);
        while ($x === $y) {
            $y = $this->rnd(1, 10);
        }
        $sum = $x + $y;
        $diff = $x - $y;
        $doubleX = 2 * $x;

        $solution = "**Решение:**\n\n"
            . "Сложим уравнения — \\(y\\) сократится:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\begin{cases} x + y = $sum \\\\ x - y = $diff \\end{cases} \\\\",
                "&2x = $doubleX \\\\",
                "&x = $x",
            ]))
            . "\n\nПодставим \\(x = $x\\) в первое уравнение:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$x + y = $sum \\;\\Rightarrow\\; y = $y",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x = $x,\\; y = $y");

        return new MathProblem(
            question: "Решите систему: " . KaTeX::inline(
                "\\begin{cases} x + y = $sum \\\\ x - y = $diff \\end{cases}"
            ),
            solution: $solution,
            answer: "x = $x, y = $y",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function bySubstitution(float $difficulty): MathProblem
    {
        // Система: 2x + y = a; x - y = b. Строим от целых x, y.
        $x = $this->rnd(1, 8);
        $y = $this->rnd(1, 8);
        while ($x === $y) {
            $y = $this->rnd(1, 8);
        }
        $a = 2 * $x + $y;
        $b = $x - $y;

        $solution = "**Решение:**\n\n"
            . "Рз второго уравнения выразим \\(x\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "x = y + $b",
            ]))
            . "\n\nПодставим в первое уравнение:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&2(y + $b) + y = $a \\\\",
                "&2y + " . (2 * $b) . " + y = $a \\\\",
                "&3y = " . ($a - 2 * $b) . " \\\\",
                "&y = $y",
            ]))
            . "\n\nНайдём \\(x\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "x = $y + $b = $x",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x = $x,\\; y = $y");

        return new MathProblem(
            question: "Решите систему: " . KaTeX::inline(
                "\\begin{cases} 2x + y = $a \\\\ x - y = $b \\end{cases}"
            ),
            solution: $solution,
            answer: "x = $x, y = $y",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
