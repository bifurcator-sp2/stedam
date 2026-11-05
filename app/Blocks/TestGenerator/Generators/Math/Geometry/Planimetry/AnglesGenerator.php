<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Geometry\Planimetry;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Углы.
 *
 * difficulty:
 *   0.0–0.4  — смежные (α + β = 180°)
 *   0.4–0.7  — вертикальные (равны)
 *   0.7–1.0  — сумма углов треугольника (α + β + γ = 180°)
 */
final class AnglesGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.geometry.planimetry.angles';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.4 => $this->adjacent($difficulty),
            $difficulty < 0.7 => $this->vertical($difficulty),
            default           => $this->triangleSum($difficulty),
        };
    }

    private function adjacent(float $difficulty): MathProblem
    {
        $a = $this->rnd(20, 160);
        $b = 180 - $a;

        $solution = "**Решение:**\n\n"
            . "Смежные углы в сумме дают \\(180^{\\circ}\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\alpha + \\beta = 180^{\\circ}",
                "$a^{\\circ} + \\beta = 180^{\\circ}",
                "\\beta = $b^{\\circ}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$b^{\\circ}");

        return new MathProblem(
            question: "Один из смежных углов равен \\($a^{\\circ}\\). Найдите второй угол.",
            solution: $solution,
            answer: "$b°",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function vertical(float $difficulty): MathProblem
    {
        $a = $this->rnd(15, 165);

        $solution = "**Решение:**\n\n"
            . "Вертикальные углы равны, поэтому второй угол — тоже \\($a^{\\circ}\\).\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\angle 1 = \\angle 2",
                "\\angle 2 = $a^{\\circ}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$a^{\\circ}");

        return new MathProblem(
            question: "Две прямые пересекаются. Один из вертикальных углов равен \\($a^{\\circ}\\). Чему равен второй?",
            solution: $solution,
            answer: "$a°",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function triangleSum(float $difficulty): MathProblem
    {
        $a = $this->rnd(30, 80);
        $b = $this->rnd(30, 80);
        while ($a + $b >= 170) {
            $b = $this->rnd(30, 80);
        }
        $c = 180 - $a - $b;

        $solution = "**Решение:**\n\n"
            . "Сумма углов треугольника равна \\(180^{\\circ}\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\alpha + \\beta + \\gamma = 180^{\\circ}",
                "$a^{\\circ} + $b^{\\circ} + \\gamma = 180^{\\circ}",
                "\\gamma = $c^{\\circ}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$c^{\\circ}");

        return new MathProblem(
            question: "Два угла треугольника равны \\($a^{\\circ}\\) и \\($b^{\\circ}\\). Найдите третий угол.",
            solution: $solution,
            answer: "$c°",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
