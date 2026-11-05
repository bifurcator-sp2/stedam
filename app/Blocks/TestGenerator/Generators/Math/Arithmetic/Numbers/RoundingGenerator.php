<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Numbers;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Округление до десятков / сотен / тысяч.
 */
final class RoundingGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.numbers.rounding';
    }

    protected function build(float $difficulty): MathProblem
    {
        [$unit, $name] = $difficulty < 0.4
            ? [10, 'десятков']
            : ($difficulty < 0.7 ? [100, 'сотен'] : [1000, 'тысяч']);

        $n = $this->rnd($unit, $unit * 99);
        $rounded = (int) (round($n / $unit) * $unit);
        $digit = intdiv($n, $unit) % 10;

        $solution = "**Решение:**\n\n"
            . "Смотрим на цифру, стоящую перед разрядом \\($name\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{исходное число: } & $n",
                "\\text{цифра в разряде «$name»: } & $digit",
                "\\text{округлённое число: } & $rounded",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $rounded);

        return new MathProblem(
            question: "Округлите \\($n\\) до $name.",
            solution: $solution,
            answer: (string) $rounded,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
