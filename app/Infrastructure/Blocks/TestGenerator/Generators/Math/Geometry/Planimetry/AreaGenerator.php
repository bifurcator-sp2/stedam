<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Generators\Math\Geometry\Planimetry;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Infrastructure\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Infrastructure\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Площадь плоских фигур.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — площадь прямоугольника
 *   [0.2–0.4) — площадь квадрата
 *   [0.4–0.6) — площадь треугольника
 *   [0.6–0.8) — площадь параллелограмма и обратная задача
 *   [0.8–1.0] — площадь трапеции и круга
 */
final class AreaGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->rectangle($difficulty, $rng),
                fn () => $this->rectangleRoom($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->square($difficulty, $rng),
                fn () => $this->squarePlot($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->rightTriangle($difficulty, $rng),
                fn () => $this->triangleBaseHeight($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->parallelogram($difficulty, $rng),
                fn () => $this->findHeight($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->trapezoid($difficulty, $rng),
                fn () => $this->circle($difficulty, $rng),
            ])(),
        };
    }

    private function rectangle(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 3, 20);
        $b = $this->rnd($rng, 3, 20);
        $s = $a * $b;

        $solution = "**Решение:**\n\n"
            . "Площадь прямоугольника: \\(S = a \\cdot b\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = $a \\cdot $b = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $s);

        return new MathProblem(
            question: "Стороны прямоугольника равны \\($a\\) см и \\($b\\) см. Найдите его площадь.",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function rectangleRoom(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 3, 12);
        $b = $this->rnd($rng, 3, 12);
        $s = $a * $b;

        $solution = "**Решение:**\n\n"
            . "Площадь пола равна произведению длины на ширину:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = $a \\cdot $b = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$s \\text{ м}^2");

        return new MathProblem(
            question: "Комната имеет длину \\($a\\) м и ширину \\($b\\) м. "
                . "Какова площадь пола?",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function square(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 20);
        $s = $a * $a;

        $solution = "**Решение:**\n\n"
            . "Площадь квадрата: \\(S = a^2\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = $a^2 = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $s);

        return new MathProblem(
            question: "Сторона квадрата равна \\($a\\) см. Найдите его площадь.",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function squarePlot(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 3, 15);
        $s = $a * $a;

        $solution = "**Решение:**\n\n"
            . "Площадь квадратного участка:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = $a^2 = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$s \\text{ м}^2");

        return new MathProblem(
            question: "Квадратный участок имеет сторону \\($a\\) м. Найдите его площадь.",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function rightTriangle(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 12) * 2;
        $b = $this->rnd($rng, 2, 12) * 2;
        $s = $a * $b / 2;

        $solution = "**Решение:**\n\n"
            . "Площадь прямоугольного треугольника: \\(S = \\dfrac{a \\cdot b}{2}\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = \\dfrac{" . $a . " \\cdot " . $b . "}{2}",
                "S = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $s);

        return new MathProblem(
            question: "Катеты прямоугольного треугольника равны \\($a\\) и \\($b\\) см. "
                . "Найдите его площадь.",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function triangleBaseHeight(float $difficulty, Randomizer $rng): MathProblem
    {
        $b = $this->rnd($rng, 2, 15) * 2;
        $h = $this->rnd($rng, 2, 15);
        $s = $b * $h / 2;

        $solution = "**Решение:**\n\n"
            . "Площадь треугольника: \\(S = \\dfrac{1}{2} b h\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = \\dfrac{1}{2} \\cdot $b \\cdot $h",
                "S = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $s);

        return new MathProblem(
            question: "Основание треугольника \\($b\\) см, высота к нему \\($h\\) см. "
                . "Найдите площадь треугольника.",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function parallelogram(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 3, 20);
        $h = $this->rnd($rng, 2, 15);
        $s = $a * $h;

        $solution = "**Решение:**\n\n"
            . "Площадь параллелограмма: \\(S = a \\cdot h\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = $a \\cdot $h = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $s);

        return new MathProblem(
            question: "Сторона параллелограмма \\($a\\) см, высота, проведённая к ней, \\($h\\) см. "
                . "Найдите площадь.",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findHeight(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 3, 20);
        $h = $this->rnd($rng, 2, 15);
        $s = $a * $h;

        $solution = "**Решение:**\n\n"
            . "Из \\(S = a \\cdot h\\) выразим высоту:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "h = \\dfrac{S}{a}",
                "h = \\dfrac{$s}{$a} = $h",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$h \\text{ см}");

        return new MathProblem(
            question: "Площадь параллелограмма \\($s\\) см², сторона \\($a\\) см. "
                . "Найдите высоту, проведённую к этой стороне.",
            solution: $solution,
            answer: (string) $h,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function trapezoid(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 10) * 2;
        $b = $this->rnd($rng, 2, 10) * 2;
        $h = $this->rnd($rng, 2, 15);
        $s = ($a + $b) / 2 * $h;

        $solution = "**Решение:**\n\n"
            . "Площадь трапеции: \\(S = \\dfrac{a + b}{2} \\cdot h\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = \\dfrac{" . ($a + $b) . "}{2} \\cdot $h",
                "S = " . (($a + $b) / 2) . " \\cdot $h = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $s);

        return new MathProblem(
            question: "Основания трапеции равны \\($a\\) и \\($b\\) см, высота \\($h\\) см. "
                . "Найдите площадь трапеции.",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function circle(float $difficulty, Randomizer $rng): MathProblem
    {
        $r = $this->rnd($rng, 2, 10);
        $s = round(3.14 * $r * $r, 2);
        $sNum = $this->num($s);

        $solution = "**Решение:**\n\n"
            . "Площадь круга: \\(S = \\pi r^2\\), принимаем \\(\\pi \\approx 3{,}14\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = 3{,}14 \\cdot $r^2",
                "S \\approx $sNum",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($sNum);

        return new MathProblem(
            question: "Радиус круга равен \\($r\\) см. Найдите площадь круга "
                . "(\\(\\pi \\approx 3{,}14\\)).",
            solution: $solution,
            answer: $sNum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
