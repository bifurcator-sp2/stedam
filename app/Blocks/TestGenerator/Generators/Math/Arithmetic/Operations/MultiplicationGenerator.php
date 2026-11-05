<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Operations;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Умножение: таблица → двузначное на однозначное → многозначное.
 */
final class MultiplicationGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.operations.multiplication';
    }

    protected function build(float $difficulty): MathProblem
    {
        [$a, $b] = match (true) {
            $difficulty < 0.3  => [$this->rnd(2, 9),     $this->rnd(2, 9)],
            $difficulty < 0.6  => [$this->rnd(10, 99),   $this->rnd(2, 9)],
            $difficulty < 0.85 => [$this->rnd(10, 99),   $this->rnd(10, 99)],
            default            => [$this->rnd(100, 999), $this->rnd(10, 99)],
        };

        $product = $a * $b;

        $solution = "**Решение:**\n\n"
            . "Умножаем по разрядам.\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\cdot $b",
                (string) $product,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $product);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a \\cdot $b"),
            solution: $solution,
            answer: (string) $product,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
