<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Generators\Math\Geometry\Stereometry;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Infrastructure\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Infrastructure\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Объём пространственных фигур.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — объём прямоугольного параллелепипеда
 *   [0.2–0.4) — объём куба
 *   [0.4–0.6) — объём параллелепипеда (обратная задача / бассейн)
 *   [0.6–0.8) — объём прямой призмы
 *   [0.8–1.0] — объём цилиндра
 */
final class VolumeGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->cuboid($difficulty, $rng),
                fn () => $this->box($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->cube($difficulty, $rng),
                fn () => $this->cubeTank($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->findDimension($difficulty, $rng),
                fn () => $this->pool($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->prism($difficulty, $rng),
                fn () => $this->prism2($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->cylinder($difficulty, $rng),
                fn () => $this->cylinderTank($difficulty, $rng),
            ])(),
        };
    }

    private function cuboid(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 10);
        $b = $this->rnd($rng, 2, 10);
        $c = $this->rnd($rng, 2, 10);
        $v = $a * $b * $c;

        $solution = "**Решение:**\n\n"
            . "Объём параллелепипеда: \\(V = a \\cdot b \\cdot c\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "V = $a \\cdot $b \\cdot $c = $v",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $v);

        return new MathProblem(
            question: "Размеры прямоугольного параллелепипеда равны \\($a\\), \\($b\\) и \\($c\\) см. "
                . "Найдите его объём.",
            solution: $solution,
            answer: (string) $v,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function box(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 10);
        $b = $this->rnd($rng, 2, 10);
        $c = $this->rnd($rng, 2, 10);
        $v = $a * $b * $c;

        $solution = "**Решение:**\n\n"
            . "Объём коробки:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "V = $a \\cdot $b \\cdot $c = $v",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$v \\text{ см}^3");

        return new MathProblem(
            question: "Коробка имеет размеры \\($a\\) см, \\($b\\) см и \\($c\\) см. "
                . "Каков её объём?",
            solution: $solution,
            answer: (string) $v,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function cube(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 10);
        $v = $a ** 3;

        $solution = "**Решение:**\n\n"
            . "Объём куба: \\(V = a^3\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "V = $a^3 = $v",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $v);

        return new MathProblem(
            question: "Ребро куба равно \\($a\\) см. Найдите его объём.",
            solution: $solution,
            answer: (string) $v,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function cubeTank(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 10);
        $v = $a ** 3;

        $solution = "**Решение:**\n\n"
            . "Ёмкость кубической формы, объём:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "V = $a^3 = $v",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$v \\text{ л}");

        return new MathProblem(
            question: "Аквариум имеет форму куба с ребром \\($a\\) дм. "
                . "Сколько в него помещается литров воды (1 дм³ = 1 л)?",
            solution: $solution,
            answer: (string) $v,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findDimension(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 8);
        $b = $this->rnd($rng, 2, 8);
        $c = $this->rnd($rng, 2, 8);
        $v = $a * $b * $c;

        $solution = "**Решение:**\n\n"
            . "Из \\(V = a \\cdot b \\cdot c\\) выразим \\(c\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "c = \\dfrac{V}{a \\cdot b}",
                "c = \\dfrac{$v}{" . ($a * $b) . "} = $c",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$c \\text{ см}");

        return new MathProblem(
            question: "Объём параллелепипеда \\($v\\) см³, два его измерения \\($a\\) и \\($b\\) см. "
                . "Найдите третье измерение.",
            solution: $solution,
            answer: (string) $c,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function pool(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 10);
        $b = $this->rnd($rng, 2, 10);
        $c = $this->rnd($rng, 1, 4);
        $v = $a * $b * $c;

        $solution = "**Решение:**\n\n"
            . "Объём бассейна:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "V = $a \\cdot $b \\cdot $c = $v",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$v \\text{ м}^3");

        return new MathProblem(
            question: "Бассейн имеет размеры \\($a\\) м × \\($b\\) м × \\($c\\) м. "
                . "Чему равен его объём?",
            solution: $solution,
            answer: (string) $v,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function prism(float $difficulty, Randomizer $rng): MathProblem
    {
        $s = $this->rnd($rng, 3, 20);
        $h = $this->rnd($rng, 2, 15);
        $v = $s * $h;

        $solution = "**Решение:**\n\n"
            . "Объём прямой призмы: \\(V = S_{\\text{осн}} \\cdot h\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "V = $s \\cdot $h = $v",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $v);

        return new MathProblem(
            question: "Площадь основания прямой призмы \\($s\\) см², высота \\($h\\) см. "
                . "Найдите объём призмы.",
            solution: $solution,
            answer: (string) $v,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function prism2(float $difficulty, Randomizer $rng): MathProblem
    {
        $s = $this->rnd($rng, 3, 20);
        $h = $this->rnd($rng, 2, 15);
        $v = $s * $h;

        $solution = "**Решение:**\n\n"
            . "Объём призмы:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "V = S_{\\text{осн}} \\cdot h = $s \\cdot $h = $v",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$v \\text{ м}^3");

        return new MathProblem(
            question: "В основании прямой призмы лежит фигура площадью \\($s\\) м², "
                . "высота призмы \\($h\\) м. Каков объём призмы?",
            solution: $solution,
            answer: (string) $v,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function cylinder(float $difficulty, Randomizer $rng): MathProblem
    {
        $r = $this->rnd($rng, 2, 6);
        $h = $this->rnd($rng, 2, 8);
        $v = round(3.14 * $r * $r * $h, 2);
        $vNum = $this->num($v);

        $solution = "**Решение:**\n\n"
            . "Объём цилиндра: \\(V = \\pi r^2 h\\), \\(\\pi \\approx 3{,}14\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "V = 3{,}14 \\cdot $r^2 \\cdot $h",
                "V \\approx $vNum",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($vNum);

        return new MathProblem(
            question: "Радиус основания цилиндра \\($r\\) см, высота \\($h\\) см. "
                . "Найдите объём (\\(\\pi \\approx 3{,}14\\)).",
            solution: $solution,
            answer: $vNum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function cylinderTank(float $difficulty, Randomizer $rng): MathProblem
    {
        $r = $this->rnd($rng, 2, 5);
        $h = $this->rnd($rng, 2, 6);
        $v = round(3.14 * $r * $r * $h, 2);
        $vNum = $this->num($v);

        $solution = "**Решение:**\n\n"
            . "Объём цилиндрической ёмкости:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "V = \\pi r^2 h",
                "V = 3{,}14 \\cdot $r^2 \\cdot $h \\approx $vNum",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($vNum);

        return new MathProblem(
            question: "Цилиндрическая бочка имеет радиус \\($r\\) дм и высоту \\($h\\) дм. "
                . "Найдите её объём (\\(\\pi \\approx 3{,}14\\)).",
            solution: $solution,
            answer: $vNum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
