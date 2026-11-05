<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Fractions;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Проценты: три классических типа.
 */
final class PercentagesGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.fractions.percentages';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.4 => $this->findPart($difficulty),
            $difficulty < 0.7 => $this->findWhole($difficulty),
            default           => $this->findRatio($difficulty),
        };
    }

    private function findPart(float $difficulty): MathProblem
    {
        $p = $this->pick([5, 10, 15, 20, 25, 50]);
        $n = $this->rnd(10, 400);
        $value = $n * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Процент — сотая доля, поэтому \\($p\\%\\) числа \\($n\\) это:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$n \\cdot \\dfrac{$p}{100}",
                $this->num($value),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($value));

        return new MathProblem(
            question: "Найдите \\($p\\%\\) от числа \\($n\\).",
            solution: $solution,
            answer: $this->num($value),
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function findWhole(float $difficulty): MathProblem
    {
        $p = $this->pick([10, 20, 25, 40, 50]);
        $whole = $this->rnd(20, 400) * 5;
        $part  = $whole * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Если \\($p\\%\\) числа равны " . $this->num($part) . ", то само число:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $this->num($part) . "}{$p} \\cdot 100",
                (string) $whole,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $whole);

        return new MathProblem(
            question: "\\($p\\%\\) числа равны " . $this->num($part) . ". Найдите это число.",
            solution: $solution,
            answer: (string) $whole,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function findRatio(float $difficulty): MathProblem
    {
        $part  = $this->rnd(2, 40);
        $whole = $part * $this->rnd(2, 10);
        $pct   = $part / $whole * 100;

        $solution = "**Решение:**\n\n"
            . "Процентное отношение — это \\( \\dfrac{\\text{часть}}{\\text{целое}} \\cdot 100\\% \\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$part}{$whole} \\cdot 100\\%",
                $this->num($pct) . "\\%",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($pct) . "\\%");

        return new MathProblem(
            question: "Сколько процентов составляет \\($part\\) от \\($whole\\)?",
            solution: $solution,
            answer: $this->num($pct) . '%',
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
