<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Geometry\Planimetry;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Углы: смежные, вертикальные, сумма углов треугольника, биссектриса.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — смежные углы
 *   [0.2–0.4) — вертикальные углы
 *   [0.4–0.6) — сумма углов треугольника
 *   [0.6–0.8) — равнобедренный треугольник
 *   [0.8–1.0] — биссектриса / углы треугольника в отношениях
 */
final class AnglesGenerator extends GeneratorBase
{
    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick([
                fn () => $this->adjacent($difficulty),
                fn () => $this->adjacentWord($difficulty),
            ])(),
            $difficulty < 0.4 => $this->pick([
                fn () => $this->vertical($difficulty),
                fn () => $this->verticalWord($difficulty),
            ])(),
            $difficulty < 0.6 => $this->pick([
                fn () => $this->thirdAngle($difficulty),
                fn () => $this->thirdAngleWord($difficulty),
            ])(),
            $difficulty < 0.8 => $this->pick([
                fn () => $this->isosceles($difficulty),
                fn () => $this->isoscelesBase($difficulty),
            ])(),
            default => $this->pick([
                fn () => $this->bisector($difficulty),
                fn () => $this->rightTriangleAngle($difficulty),
            ])(),
        };
    }

    private function adjacent(float $difficulty): MathProblem
    {
        $a = $this->rnd(20, 160);
        $b = 180 - $a;

        $solution = "**Решение:**\n\n"
            . "Сумма смежных углов равна \\(180^\\circ\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "b = 180^\\circ - $a^\\circ",
                "b = $b^\\circ",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$b^\\circ");

        return new MathProblem(
            question: "Один из смежных углов равен \\($a^\\circ\\). Найдите второй угол.",
            solution: $solution,
            answer: $b . 'В°',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function adjacentWord(float $difficulty): MathProblem
    {
        $a = $this->rnd(20, 160);
        $b = 180 - $a;

        $solution = "**Решение:**\n\n"
            . "Смежные углы в сумме дают развёрнутый угол \\(180^\\circ\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "180^\\circ - $a^\\circ = $b^\\circ",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$b^\\circ");

        return new MathProblem(
            question: "Луч делит развёрнутый угол на два смежных. Один из них \\($a^\\circ\\). "
                . "Чему равен другой?",
            solution: $solution,
            answer: $b . 'В°',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function vertical(float $difficulty): MathProblem
    {
        $a = $this->rnd(20, 160);

        $solution = "**Решение:**\n\n"
            . "Вертикальные углы равны:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\angle 1 = \\angle 3 = $a^\\circ",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$a^\\circ");

        return new MathProblem(
            question: "При пересечении двух прямых один из углов равен \\($a^\\circ\\). "
                . "Найдите вертикальный ему угол.",
            solution: $solution,
            answer: $a . 'В°',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function verticalWord(float $difficulty): MathProblem
    {
        $a = $this->rnd(20, 160);

        $solution = "**Решение:**\n\n"
            . "Вертикальные углы равны, поэтому равный угол тоже \\($a^\\circ\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\angle = $a^\\circ",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$a^\\circ");

        return new MathProblem(
            question: "Два угла образованы пересечением двух прямых и не являются смежными. "
                . "Один из них \\($a^\\circ\\). Чему равен второй?",
            solution: $solution,
            answer: $a . 'В°',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function thirdAngle(float $difficulty): MathProblem
    {
        $a = $this->rnd(20, 100);
        $b = $this->rnd(20, 180 - $a - 1);
        $c = 180 - $a - $b;

        $solution = "**Решение:**\n\n"
            . "Сумма углов треугольника равна \\(180^\\circ\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "c = 180^\\circ - $a^\\circ - $b^\\circ",
                "c = $c^\\circ",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$c^\\circ");

        return new MathProblem(
            question: "Два угла треугольника равны \\($a^\\circ\\) и \\($b^\\circ\\). "
                . "Найдите третий угол.",
            solution: $solution,
            answer: $c . 'В°',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function thirdAngleWord(float $difficulty): MathProblem
    {
        $a = $this->rnd(20, 90);
        $b = $this->rnd(20, 180 - $a - 1);
        $c = 180 - $a - $b;

        $solution = "**Решение:**\n\n"
            . "Сумма углов треугольника \\(180^\\circ\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "180^\\circ - $a^\\circ - $b^\\circ = $c^\\circ",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$c^\\circ");

        return new MathProblem(
            question: "В треугольнике известны углы \\($a^\\circ\\) и \\($b^\\circ\\). "
                . "Чему равен оставшийся угол?",
            solution: $solution,
            answer: $c . 'В°',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function isosceles(float $difficulty): MathProblem
    {
        $apex = $this->rnd(20, 140);
        $c = 180 - $apex;
        if ($c % 2 !== 0) {
            $apex += 1;
            $c = 180 - $apex;
        }
        $base = intdiv($c, 2);

        $solution = "**Решение:**\n\n"
            . "Углы при основании равны. Сумма углов \\(180^\\circ\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\angle A = \\angle B = \\dfrac{180^\\circ - $apex^\\circ}{2}",
                "\\angle A = $base^\\circ",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$base^\\circ");

        return new MathProblem(
            question: "В равнобедренном треугольнике угол при вершине равен \\($apex^\\circ\\). "
                . "Найдите угол при основании.",
            solution: $solution,
            answer: $base . 'В°',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function isoscelesBase(float $difficulty): MathProblem
    {
        $base = $this->rnd(20, 80);
        $apex = 180 - 2 * $base;

        $solution = "**Решение:**\n\n"
            . "Углы при основании равны, значит сумма при основании \\(2 \\cdot $base^\\circ\\). "
            . "Угол при вершине:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "180^\\circ - 2 \\cdot $base^\\circ",
                "= $apex^\\circ",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$apex^\\circ");

        return new MathProblem(
            question: "В равнобедренном треугольнике угол при основании равен \\($base^\\circ\\). "
                . "Найдите угол при вершине.",
            solution: $solution,
            answer: $apex . 'В°',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function bisector(float $difficulty): MathProblem
    {
        $a = $this->rnd(20, 80) * 2;
        $half = $a / 2;

        $solution = "**Решение:**\n\n"
            . "Биссектриса делит угол пополам:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $a . "^\\circ}{2} = $half^\\circ",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$half^\\circ");

        return new MathProblem(
            question: "Угол равен \\($a^\\circ\\). Чему равен каждый из углов, "
                . "на которые его делит биссектриса?",
            solution: $solution,
            answer: $half . 'В°',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function rightTriangleAngle(float $difficulty): MathProblem
    {
        $a = $this->rnd(10, 80);
        $b = 90 - $a;

        $solution = "**Решение:**\n\n"
            . "В прямоугольном треугольнике сумма острых углов \\(90^\\circ\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "b = 90^\\circ - $a^\\circ",
                "b = $b^\\circ",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$b^\\circ");

        return new MathProblem(
            question: "Один острый угол прямоугольного треугольника равен \\($a^\\circ\\). "
                . "Найдите второй острый угол.",
            solution: $solution,
            answer: $b . 'В°',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
