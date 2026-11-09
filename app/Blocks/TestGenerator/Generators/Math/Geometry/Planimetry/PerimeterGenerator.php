<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Geometry\Planimetry;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Периметр плоских фигур.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — периметр прямоугольника
 *   [0.2–0.4) — периметр квадрата и равностороннего треугольника
 *   [0.4–0.6) — периметр разностороннего треугольника, правильный многоугольник
 *   [0.6–0.8) — периметр прямоугольника/квадрата, найти сторону
 *   [0.8–1.0] — длина окружности / прямоугольная рамка
 */
final class PerimeterGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->rectangle($difficulty, $rng),
                fn () => $this->rectangleField($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->square($difficulty, $rng),
                fn () => $this->triangle($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->scaleneTriangle($difficulty, $rng),
                fn () => $this->regularPolygon($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->findSide($difficulty, $rng),
                fn () => $this->findSquareSide($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->circumference($difficulty, $rng),
                fn () => $this->composite($difficulty, $rng),
            ])(),
        };
    }

    private function rectangle(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 3, 20);
        $b = $this->rnd($rng, 3, 20);
        $p = 2 * ($a + $b);

        $solution = "**Решение:**\n\n"
            . "Периметр прямоугольника: \\(P = 2(a + b)\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P = 2($a + $b)",
                "P = 2 \\cdot " . ($a + $b) . " = $p",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $p);

        return new MathProblem(
            question: "Стороны прямоугольника равны \\($a\\) см и \\($b\\) см. Найдите его периметр.",
            solution: $solution,
            answer: (string) $p,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function rectangleField(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 50);
        $b = $this->rnd($rng, 10, 50);
        $p = 2 * ($a + $b);

        $solution = "**Решение:**\n\n"
            . "Периметр прямоугольного участка:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P = 2($a + $b)",
                "P = 2 \\cdot " . ($a + $b) . " = $p",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$p \\text{ м}");

        return new MathProblem(
            question: "Прямоугольный участок имеет размеры \\($a\\) м на \\($b\\) м. "
                . "Сколько метров забора нужно, чтобы обнести участок по периметру?",
            solution: $solution,
            answer: (string) $p,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function square(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 25);
        $p = 4 * $a;

        $solution = "**Решение:**\n\n"
            . "Периметр квадрата: \\(P = 4a\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P = 4 \\cdot $a",
                "P = $p",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $p);

        return new MathProblem(
            question: "Сторона квадрата равна \\($a\\) см. Найдите его периметр.",
            solution: $solution,
            answer: (string) $p,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function triangle(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 3, 20);
        $p = 3 * $a;

        $solution = "**Решение:**\n\n"
            . "У равностороннего треугольника три равные стороны: \\(P = 3a\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P = 3 \\cdot $a",
                "P = $p",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $p);

        return new MathProblem(
            question: "Сторона равностороннего треугольника равна \\($a\\) см. "
                . "Чему равен его периметр?",
            solution: $solution,
            answer: (string) $p,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function scaleneTriangle(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 3, 15);
        $b = $this->rnd($rng, 3, 15);
        $c = $this->rnd($rng, 3, 15);
        $p = $a + $b + $c;

        $solution = "**Решение:**\n\n"
            . "Периметр треугольника — сумма всех сторон:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P = $a + $b + $c",
                "P = $p",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $p);

        return new MathProblem(
            question: "Стороны треугольника равны \\($a\\), \\($b\\) и \\($c\\) см. "
                . "Найдите его периметр.",
            solution: $solution,
            answer: (string) $p,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function regularPolygon(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->pick($rng, [5, 6, 8]);
        $a = $this->rnd($rng, 2, 15);
        $p = $n * $a;

        $solution = "**Решение:**\n\n"
            . "У правильного \\($n\\)-угольника все стороны равны: \\(P = $n \\cdot a\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P = $n \\cdot $a",
                "P = $p",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $p);

        return new MathProblem(
            question: "Сторона правильного \\($n\\)-угольника равна \\($a\\) см. "
                . "Найдите его периметр.",
            solution: $solution,
            answer: (string) $p,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findSide(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 3, 20);
        $b = $this->rnd($rng, 3, 20);
        $p = 2 * ($a + $b);

        $solution = "**Решение:**\n\n"
            . "Из формулы \\(P = 2(a + b)\\) выразим \\(a\\). Сначала \\(a + b = P / 2\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "a + b = \\dfrac{$p}{2} = " . ($a + $b),
                "a = " . ($a + $b) . " - $b = $a",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$a \\text{ см}");

        return new MathProblem(
            question: "Периметр прямоугольника равен \\($p\\) см, одна сторона \\($b\\) см. "
                . "Найдите другую сторону.",
            solution: $solution,
            answer: (string) $a,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findSquareSide(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 25);
        $p = 4 * $a;

        $solution = "**Решение:**\n\n"
            . "Сторона квадрата — четверть периметра:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "a = \\dfrac{P}{4}",
                "a = \\dfrac{$p}{4} = $a",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$a \\text{ см}");

        return new MathProblem(
            question: "Периметр квадрата равен \\($p\\) см. Найдите его сторону.",
            solution: $solution,
            answer: (string) $a,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function circumference(float $difficulty, Randomizer $rng): MathProblem
    {
        $d = $this->rnd($rng, 2, 20);
        $c = round(3.14 * $d, 2);
        $cNum = $this->num($c);

        $solution = "**Решение:**\n\n"
            . "Длина окружности: \\(C = \\pi d\\), принимаем \\(\\pi \\approx 3{,}14\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "C = 3{,}14 \\cdot $d",
                "C \\approx $cNum",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($cNum);

        return new MathProblem(
            question: "Диаметр окружности равен \\($d\\) см. Найдите длину окружности "
                . "(\\(\\pi \\approx 3{,}14\\)).",
            solution: $solution,
            answer: $cNum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function composite(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 4, 12);
        $b = $this->rnd($rng, 4, 12);
        $p = 2 * ($a + $b);

        $solution = "**Решение:**\n\n"
            . "Периметр прямоугольной рамки — сумма длин её сторон:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P = 2($a + $b)",
                "P = 2 \\cdot " . ($a + $b) . " = $p",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$p \\text{ см}");

        return new MathProblem(
            question: "Прямоугольная рамка имеет длину \\($a\\) см и ширину \\($b\\) см. "
                . "Сколько сантиметров планки нужно на весь периметр?",
            solution: $solution,
            answer: (string) $p,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
