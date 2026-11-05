<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Operations;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Вычитание: всегда неотрицательный результат.
 */
final class SubtractionGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.operations.subtraction';
    }

    protected function build(float $difficulty): MathProblem
    {
        $max = match (true) {
            $difficulty < 0.25 => 10,
            $difficulty < 0.5  => 100,
            $difficulty < 0.75 => 1000,
            default            => 10000,
        };

        $a = $this->rnd(1, $max);
        $b = $this->rnd(1, $a);
        if ($b === $a) {
            $b = max(1, $a - 1);
        }

        $diff = $a - $b;

        $solution = "**Решение:**\n\n"
            . "Вычитаем по разрядам, начиная с единиц.\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a - $b",
                (string) $diff,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $diff);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a - $b"),
            solution: $solution,
            answer: (string) $diff,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
