<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Geometry\Planimetry;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Площадь фигур.
 *
 * difficulty:
 *   0.0–0.3  — прямоугольник (S = a·b)
 *   0.3–0.6  — треугольник   (S = ½·a·h)
 *   0.6–0.85 — параллелограмм (S = a·h)
 *   0.85–1.0 — трапеция       (S = ((a+b)/2)·h)
 */
final class AreaGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.geometry.planimetry.area';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.30 => $this->rectangle($difficulty),
            $difficulty < 0.60 => $this->triangle($difficulty),
            $difficulty < 0.85 => $this->parallelogram($difficulty),
            default            => $this->trapezoid($difficulty),
        };
    }

    private function rectangle(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 20);
        $b = $this->rnd(2, 20);
        $s = $a * $b;

        $solution = "**Решение:**\n\n"
            . "Площадь прямоугольника:\n\n"
            . KaTeX::display('S = a \cdot b')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = $a \\cdot $b",
                "S = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $s);

        return new MathProblem(
            question: "Найдите площадь прямоугольника со сторонами \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $s,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function triangle(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 20);
        $h = $this->rnd(2, 20);
        $s = $a * $h / 2;
        $answer = $this->num($s);

        $solution = "**Решение:**\n\n"
            . "Площадь треугольника:\n\n"
            . KaTeX::display('S = \dfrac{1}{2} \cdot a \cdot h')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = \\dfrac{1}{2} \\cdot $a \\cdot $h",
                "S = \\dfrac{" . ($a * $h) . "}{2}",
                "S = $answer",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($answer);

        return new MathProblem(
            question: "Найдите площадь треугольника с основанием \\($a\\) и высотой \\($h\\).",
            solution: $solution,
            answer: $answer,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function parallelogram(float $difficulty): MathProblem
    {
        $a = $this->rnd(3, 20);
        $h = $this->rnd(2, 15);
        $s = $a * $h;

        $solution = "**Решение:**\n\n"
            . "Площадь параллелограмма:\n\n"
            . KaTeX::display('S = a \cdot h')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = $a \\cdot $h",
                "S = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $s);

        return new MathProblem(
            question: "Найдите площадь параллелограмма со стороной \\($a\\) и высотой \\($h\\), проведённой к этой стороне.",
            solution: $solution,
            answer: (string) $s,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function trapezoid(float $difficulty): MathProblem
    {
        $a = $this->rnd(3, 15);
        $b = $this->rnd(3, 15);
        while ($a === $b) {
            $b = $this->rnd(3, 15);
        }
        $h = $this->rnd(2, 12);
        $s = ($a + $b) * $h / 2;
        $answer = $this->num($s);

        $solution = "**Решение:**\n\n"
            . "Площадь трапеции:\n\n"
            . KaTeX::display('S = \dfrac{a + b}{2} \cdot h')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                // ФИКС: было "\\dfrac{$a + $b}{2}" — невалидный PHP.
                // Стало — раздельные complex-блоки {{$a} + {$b}}.
                "S = \\dfrac{{$a} + {$b}}{2} \\cdot $h",
                "S = \\dfrac{" . ($a + $b) . "}{2} \\cdot $h",
                "S = $answer",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($answer);

        return new MathProblem(
            question: "Найдите площадь трапеции с основаниями \\($a\\) и \\($b\\) и высотой \\($h\\).",
            solution: $solution,
            answer: $answer,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
