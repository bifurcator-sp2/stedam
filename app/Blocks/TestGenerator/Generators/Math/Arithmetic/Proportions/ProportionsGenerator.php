<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Proportions;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Пропорции.
 *
 * difficulty:
 *   0.0–0.5 — найти неизвестный член a/b = c/x
 *   0.5–1.0 — задача на пропорцию (масштаб / прямая пропорциональность)
 */
final class ProportionsGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.proportions.proportions';
    }

    protected function build(float $difficulty): MathProblem
    {
        return $difficulty < 0.5
            ? $this->solveForX($difficulty)
            : $this->wordProblem($difficulty);
    }

    private function solveForX(float $difficulty): MathProblem
    {
        // Строим от целого x: b·c должно делиться на a.
        $a = $this->rnd(2, 9);
        $x = $this->rnd(2, 12);
        // c/a — целое, чтобы b·c/a было целым при любом b.
        $c = $a * $this->rnd(2, 9);
        $b = $this->rnd(2, 12);

        $solution = "**Решение:**\n\n"
            . "По основному свойству пропорции произведение крайних членов "
            . "равно произведению средних:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$a}{$b} = \\dfrac{$c}{x} \\\\",
                "&$a \\cdot x = $b \\cdot $c \\\\",
                "&x = \\dfrac{{$b} \\cdot {$c}}{$a} = $x",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x = $x");

        return new MathProblem(
            question: "Решите пропорцию: " . KaTeX::inline("\\dfrac{$a}{$b} = \\dfrac{$c}{x}"),
            solution: $solution,
            answer: "x = $x",
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function wordProblem(float $difficulty): MathProblem
    {
        // Прямая пропорциональность: k кг стоят m ₽, сколько стоят n кг?
        $pricePerKg = $this->rnd(20, 200);
        $k = $this->rnd(2, 8);
        $n = $this->rnd(2, 10);
        while ($n === $k) {
            $n = $this->rnd(2, 10);
        }
        $m = $k * $pricePerKg;         // стоимость k кг
        $target = $n * $pricePerKg;    // стоимость n кг

        $solution = "**Решение:**\n\n"
            . "Составим пропорцию: \\($k\\) кг — \\($m\\) ₽, \\($n\\) кг — \\(x\\) ₽.\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$k}{$n} = \\dfrac{$m}{x} \\\\",
                "&$k \\cdot x = $n \\cdot $m \\\\",
                "&x = \\dfrac{" . ($n * $m) . "}{$k} = $target",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$target \\text{ ₽}");

        return new MathProblem(
            question: "\\($k\\) кг картофеля стоят \\($m\\) ₽. "
            . "Сколько стоят \\($n\\) кг?",
            solution: $solution,
            answer: (string) $target,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
