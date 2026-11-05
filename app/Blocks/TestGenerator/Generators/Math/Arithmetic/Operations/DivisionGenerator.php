<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Operations;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Деление: точное (делимое строится от делителя и частного),
 * на высоких сложностях — с остатком.
 */
final class DivisionGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.operations.division';
    }

    protected function build(float $difficulty): MathProblem
    {
        $withRemainder = $difficulty >= 0.75;

        [$divisor, $maxQuotient] = match (true) {
            $difficulty < 0.3  => [$this->rnd(2, 9),   10],
            $difficulty < 0.6  => [$this->rnd(2, 9),   20],
            $difficulty < 0.85 => [$this->rnd(10, 99), 20],
            default            => [$this->rnd(2, 20),  50],
        };

        $quotient  = $this->rnd(1, $maxQuotient);
        $remainder = $withRemainder ? $this->rnd(0, $divisor - 1) : 0;
        $dividend  = $divisor * $quotient + $remainder;

        $answer = $withRemainder
            ? "неполное частное $quotient, остаток $remainder"
            : (string) $quotient;

        $steps = $withRemainder
            ? [
                "$dividend \\div $divisor",
                "$divisor \\cdot $quotient = " . ($divisor * $quotient),
                "$dividend - " . ($divisor * $quotient) . " = $remainder",
                "\\Rightarrow\\; $quotient \\text{ (ост. } $remainder\\text{)}",
            ]
            : [
                "$dividend \\div $divisor",
                (string) $quotient,
            ];

        $solution = "**Решение:**\n\n"
            . KaTeX::display(KaTeX::aligned($steps))
            . "\n\n**Ответ:** " . KaTeX::inline($answer);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$dividend \\div $divisor"),
            solution: $solution,
            answer: $answer,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
