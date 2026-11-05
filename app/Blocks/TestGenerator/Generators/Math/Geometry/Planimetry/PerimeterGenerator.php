<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Geometry\Planimetry;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Периметр фигур.
 *
 * difficulty:
 *   0.0–0.5 — квадрат (P = 4a)
 *   0.5–1.0 — прямоугольник (P = 2(a+b))
 */
final class PerimeterGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.geometry.planimetry.perimeter';
    }

    protected function build(float $difficulty): MathProblem
    {
        return $difficulty < 0.5
            ? $this->square($difficulty)
            : $this->rectangle($difficulty);
    }

    private function square(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 25);
        $p = 4 * $a;

        $solution = "**Решение:**\n\n"
            . "Периметр квадрата:\n\n"
            . KaTeX::display('P = 4a')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P = 4 \\cdot $a",
                "P = $p",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $p);

        return new MathProblem(
            question: "Найдите периметр квадрата со стороной \\($a\\).",
            solution: $solution,
            answer: (string) $p,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function rectangle(float $difficulty): MathProblem
    {
        $a = $this->rnd(3, 30);
        $b = $this->rnd(3, 30);
        while ($a === $b) {
            $b = $this->rnd(3, 30);
        }
        $p = 2 * ($a + $b);

        $solution = "**Решение:**\n\n"
            . "Периметр прямоугольника:\n\n"
            . KaTeX::display('P = 2(a + b)')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P = 2 \\cdot ($a + $b)",
                "P = 2 \\cdot " . ($a + $b),
                "P = $p",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $p);

        return new MathProblem(
            question: "Найдите периметр прямоугольника со сторонами \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $p,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
