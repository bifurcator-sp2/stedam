<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Geometry\Stereometry;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Объём тел.
 *
 * difficulty:
 *   0.0–0.4  — прямоугольный параллелепипед (V = a·b·c)
 *   0.4–0.7  — куб (V = a³)
 *   0.7–1.0  — цилиндр (V = πr²h), ответ округляется до сотых
 */
final class VolumeGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.geometry.stereometry.volume';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.4 => $this->cuboid($difficulty),
            $difficulty < 0.7 => $this->cube($difficulty),
            default           => $this->cylinder($difficulty),
        };
    }

    private function cuboid(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 10);
        $b = $this->rnd(2, 10);
        $c = $this->rnd(2, 10);
        $v = $a * $b * $c;

        $solution = "**Решение:**\n\n"
            . "Объём прямоугольного параллелепипеда:\n\n"
            . KaTeX::display('V = a \cdot b \cdot c')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "V = $a \\cdot $b \\cdot $c",
                "V = $v",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $v);

        return new MathProblem(
            question: "Найдите объём прямоугольного параллелепипеда с измерениями \\($a\\), \\($b\\), \\($c\\).",
            solution: $solution,
            answer: (string) $v,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function cube(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 10);
        $v = $a ** 3;

        $solution = "**Решение:**\n\n"
            . "Объём куба:\n\n"
            . KaTeX::display('V = a^{3}')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "V = $a^{3}",
                "V = $v",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $v);

        return new MathProblem(
            question: "Найдите объём куба с ребром \\($a\\).",
            solution: $solution,
            answer: (string) $v,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function cylinder(float $difficulty): MathProblem
    {
        $r = $this->rnd(1, 10);
        $h = $this->rnd(1, 15);
        $v = M_PI * $r * $r * $h;
        $answer = $this->num($v, 2);

        $solution = "**Решение:**\n\n"
            . "Объём цилиндра:\n\n"
            . KaTeX::display('V = \pi r^{2} h')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "V &= \\pi \\cdot $r^{2} \\cdot $h \\\\",
                "  &= " . $this->num($r * $r) . " \\pi \\cdot $h \\\\",
                "  &= " . $this->num($r * $r * $h) . " \\pi \\\\",
                "  &\\approx $answer",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($answer . " \\text{ (при } \\pi \\approx 3{,}14\\text{)}");

        return new MathProblem(
            question: "Найдите объём цилиндра с радиусом основания \\($r\\) и высотой \\($h\\). Ответ округлите до сотых.",
            solution: $solution,
            answer: $answer,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
