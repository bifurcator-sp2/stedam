<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Operations;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Сложение: от чисел в пределах 10 до многозначных.
 */
final class AdditionGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.operations.addition';
    }

    protected function build(float $difficulty): MathProblem
    {
        [$a, $b] = match (true) {
            $difficulty < 0.25 => [$this->rnd(1, 9),     $this->rnd(1, 9)],
            $difficulty < 0.5  => [$this->rnd(10, 99),   $this->rnd(10, 99)],
            $difficulty < 0.75 => [$this->rnd(100, 999), $this->rnd(10, 999)],
            default            => [$this->rnd(1000, 9999), $this->rnd(100, 9999)],
        };

        $sum = $a + $b;

        $solution = "**Решение:**\n\n"
            . "Складываем по разрядам, начиная с единиц.\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a + $b",
                (string) $sum,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $sum);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a + $b"),
            solution: $solution,
            answer: (string) $sum,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
