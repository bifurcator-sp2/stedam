<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Numbers;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Разряды: собрать число из сотен, десятков и единиц.
 */
final class PlaceValueGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.numbers.place_value';
    }

    protected function build(float $difficulty): MathProblem
    {
        $maxDigit = $difficulty < 0.5 ? 5 : 9;

        $h = $this->rnd(1, $maxDigit);
        $d = $this->rnd(0, 9);
        $u = $this->rnd(0, 9);
        $number = $h * 100 + $d * 10 + $u;

        $solution = "**Решение:**\n\n"
            . "Составим число по разрядам:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{сотни: } & $h \\cdot 100 = " . ($h * 100),
                "\\text{десятки: } & $d \\cdot 10 = " . ($d * 10),
                "\\text{единицы: } & $u",
                "\\text{сумма: } & " . ($h * 100) . " + " . ($d * 10) . " + $u = $number",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $number);

        return new MathProblem(
            question: "Запишите число, в котором \\($h\\) сотен, \\($d\\) десятков и \\($u\\) единиц.",
            solution: $solution,
            answer: (string) $number,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
