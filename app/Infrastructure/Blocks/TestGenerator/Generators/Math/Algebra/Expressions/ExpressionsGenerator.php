<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Generators\Math\Algebra\Expressions;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Infrastructure\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Infrastructure\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Буквенные выражения.
 *
 * difficulty:
 *   0.0–0.3 — подстановка: найти значение ax + b при x = k
 *   0.3–0.6 — приведение подобных: ax + bx = (a+b)x
 *   0.6–1.0 — раскрытие скобок: a(x + b) = ax + ab
 */
final class ExpressionsGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.3 => $this->substitution($difficulty, $rng),
            $difficulty < 0.6 => $this->combineLike($difficulty, $rng),
            default           => $this->expandBrackets($difficulty, $rng),
        };
    }

    private function substitution(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 9);
        $b = $this->rnd($rng, 1, 20);
        $x = $this->rnd($rng, 1, 10);

        $product = $a * $x;
        $value = $product + $b;

        $solution = "**Решение:**\n\n"
            . "Подставим \\(x = $x\\) в выражение:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&{$a}x + {$b} \\\\",
                "&= $a \\cdot $x + $b \\\\",
                "&= $product + $b \\\\",
                "&= $value",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $value);

        return new MathProblem(
            question: "Найдите значение выражения " . KaTeX::inline("{$a}x + {$b}")
            . " при \\(x = $x\\).",
            solution: $solution,
            answer: (string) $value,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function combineLike(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 9);
        $b = $this->rnd($rng, 2, 9);
        $sum = $a + $b;

        $solution = "**Решение:**\n\n"
            . "Подобные слагаемые — с одинаковой буквенной частью. "
            . "Складываем коэффициенты:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&{$a}x + {$b}x \\\\",
                "&= ({$a} + {$b})x \\\\",
                "&= {$sum}x",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("{$sum}x");

        return new MathProblem(
            question: "Упростите выражение: " . KaTeX::inline("{$a}x + {$b}x"),
            solution: $solution,
            answer: "{$sum}x",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function expandBrackets(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 9);
        $b = $this->rnd($rng, 2, 9);
        $product = $a * $b;

        $solution = "**Решение:**\n\n"
            . "Раскроем скобки по распределительному свойству:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&{$a}(x + {$b}) \\\\",
                "&= {$a}x + {$a} \\cdot {$b} \\\\",
                "&= {$a}x + {$product}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("{$a}x + {$product}");

        return new MathProblem(
            question: "Раскройте скобки: " . KaTeX::inline("{$a}(x + {$b})"),
            solution: $solution,
            answer: "{$a}x + {$product}",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
