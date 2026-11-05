<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Numbers;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Счёт: продолжить последовательность с заданным шагом.
 */
final class CountingGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.numbers.counting';
    }

    protected function build(float $difficulty): MathProblem
    {
        $limit = (int) round(10 + $difficulty * 90);
        $step  = $difficulty < 0.4
            ? 1
            : ($difficulty < 0.7 ? $this->pick([2, 5, 10]) : $this->pick([2, 3, 4, 5, 10]));

        $start = $this->rnd(1, max(1, $limit - 5 * $step));
        $count = $this->rnd(3, 6);

        $seq = [];
        for ($i = 0; $i < $count; $i++) {
            $seq[] = $start + $i * $step;
        }
        $next = $start + $count * $step;
        $shown = implode(',\\; ', $seq);

        $solution = "**Решение:**\n\n"
            . "Каждое следующее число больше предыдущего на \\($step\\).\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{Последовательность: } $shown, \\ldots",
                "\\text{Следующее: } $next",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $next);

        return new MathProblem(
            question: "Продолжите последовательность: \\($shown, \\ldots\\). Какое число идёт следующим?",
            solution: $solution,
            answer: (string) $next,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
