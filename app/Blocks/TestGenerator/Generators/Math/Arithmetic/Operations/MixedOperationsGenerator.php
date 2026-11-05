<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Operations;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Смешанные вычисления: порядок действий.
 *
 * difficulty:
 *   0.0–0.3  — a + b · c   (приоритет умножения над сложением)
 *   0.3–0.6  — (a + b) · c (скобки меняют порядок)
 *   0.6–1.0  — a + b · c − d : e
 */
final class MixedOperationsGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.arithmetic.operations.mixed';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.3 => $this->addMul($difficulty),
            $difficulty < 0.6 => $this->withBrackets($difficulty),
            default           => $this->full($difficulty),
        };
    }

    private function addMul(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 20);
        $b = $this->rnd(2, 9);
        $c = $this->rnd(2, 9);

        $product = $b * $c;
        $result  = $a + $product;

        $solution = "**Решение:**\n\n"
            . "Сначала выполняем умножение, затем сложение.\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&$a + $b \\cdot $c \\\\",
                "&= $a + $product \\\\",
                "&= $result",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $result);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a + $b \\cdot $c"),
            solution: $solution,
            answer: (string) $result,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function withBrackets(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 15);
        $b = $this->rnd(2, 15);
        $c = $this->rnd(2, 9);

        $sum    = $a + $b;
        $result = $sum * $c;

        $solution = "**Решение:**\n\n"
            . "Сначала выполняем действие в скобках, затем умножение.\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&({$a} + {$b}) \\cdot $c \\\\",
                "&= $sum \\cdot $c \\\\",
                "&= $result",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $result);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("({$a} + {$b}) \\cdot $c"),
            solution: $solution,
            answer: (string) $result,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function full(float $difficulty): MathProblem
    {
        $a = $this->rnd(10, 50);
        $b = $this->rnd(2, 9);
        $c = $this->rnd(2, 9);
        $d = $this->rnd(2, 9);
        $e = $this->rnd(2, 9);

        $product = $b * $c;
        $div     = $d * $e;              // гарантируем целочисленное деление
        $quotient = $div / $e;           // = $d

        $result = $a + $product - $quotient;

        $solution = "**Решение:**\n\n"
            . "Порядок действий: сначала умножение и деление (слева направо), "
            . "затем сложение и вычитание (слева направо).\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&$a + $b \\cdot $c - $div : $e \\\\",
                "&= $a + $product - $quotient \\\\",
                "&= $result",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $result);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a + $b \\cdot $c - $div : $e"),
            solution: $solution,
            answer: (string) $result,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
