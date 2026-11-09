<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Operations;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Смешанные выражения: несколько действий.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0вЂ“0.2) вЂ” a + b В· c
 *   [0.2вЂ“0.4) вЂ” (a + b) В· c
 *   [0.4вЂ“0.6) вЂ” a + b В· c в€’ d
 *   [0.6вЂ“0.8) вЂ” a + b В· c в€’ d : e
 *   [0.8–1.0] — выражение со скобками и делением
 */
final class MixedOperationsGenerator extends GeneratorBase
{
    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick([
                fn () => $this->addMul($difficulty),
                fn () => $this->buyAddMul($difficulty),
            ])(),
            $difficulty < 0.4 => $this->pick([
                fn () => $this->withBrackets($difficulty),
                fn () => $this->groupBrackets($difficulty),
            ])(),
            $difficulty < 0.6 => $this->pick([
                fn () => $this->addMulSub($difficulty),
                fn () => $this->changeMulSub($difficulty),
            ])(),
            $difficulty < 0.8 => $this->pick([
                fn () => $this->full($difficulty),
                fn () => $this->shopFull($difficulty),
            ])(),
            default => $this->pick([
                fn () => $this->bracketsAndDivision($difficulty),
                fn () => $this->twoBrackets($difficulty),
            ])(),
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
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function buyAddMul(float $difficulty): MathProblem
    {
        $base = $this->rnd(2, 20);
        $price = $this->rnd(2, 9);
        $count = $this->rnd(2, 9);
        $cost = $price * $count;
        $result = $base + $cost;

        $solution = "**Решение:**\n\n"
            . "Сначала стоимость покупки, затем прибавим оставшиеся деньги:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&$base + $price \\cdot $count \\\\",
                "&= $base + $cost \\\\",
                "&= $result",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $result . " \\text{ ₽}");

        return new MathProblem(
            question: "У покупателя было \\($base\\) ₽, и он купил \\($count\\) товаров по \\($price\\) ₽. "
                . "Сколько денег у него останется? (если бы не хватало — сколько нужно)",
            solution: $solution,
            answer: (string) $result,
            topic: $this->path(),
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
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function groupBrackets(float $difficulty): MathProblem
    {
        $x = $this->rnd(2, 15);
        $y = $this->rnd(2, 15);
        $k = $this->rnd(2, 9);
        $sum = $x + $y;
        $result = $sum * $k;

        $solution = "**Решение:**\n\n"
            . "Сумма в скобках, затем умножение на \\($k\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&({$x} + {$y}) \\cdot $k \\\\",
                "&= $sum \\cdot $k \\\\",
                "&= $result",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $result);

        return new MathProblem(
            question: "В \\($k\\) коробках лежит по \\($x\\) и \\($y\\) предметов. "
                . "Сколько всего предметов? Запишите выражение " . KaTeX::inline("($x + $y) \\cdot $k") . ".",
            solution: $solution,
            answer: (string) $result,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function addMulSub(float $difficulty): MathProblem
    {
        $a = $this->rnd(10, 50);
        $b = $this->rnd(2, 9);
        $c = $this->rnd(2, 9);
        $d = $this->rnd(2, 9);

        $product = $b * $c;
        $result  = $a + $product - $d;

        $solution = "**Решение:**\n\n"
            . "Сначала умножение, затем сложение и вычитание по порядку.\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&$a + $b \\cdot $c - $d \\\\",
                "&= $a + $product - $d \\\\",
                "&= " . ($a + $product) . " - $d \\\\",
                "&= $result",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $result);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a + $b \\cdot $c - $d"),
            solution: $solution,
            answer: (string) $result,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function changeMulSub(float $difficulty): MathProblem
    {
        $a = $this->rnd(10, 50);
        $b = $this->rnd(2, 9);
        $c = $this->rnd(2, 9);
        $d = $this->rnd(2, $b * $c);
        $product = $b * $c;
        $result = $a + $product - $d;

        $solution = "**Решение:**\n\n"
            . "Умножение первым, затем сложение и вычитание:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&$a + $b \\cdot $c - $d \\\\",
                "&= $a + $product - $d \\\\",
                "&= $result",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $result);

        return new MathProblem(
            question: "В кассе было \\($a\\) ₽, принесли \\($b\\) раз по \\($c\\) ₽, "
                . "затем выдали \\($d\\) ₽. Сколько денег осталось?",
            solution: $solution,
            answer: (string) $result,
            topic: $this->path(),
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

        $product  = $b * $c;
        $div      = $d * $e;
        $quotient = $div / $e;

        $result = $a + $product - $quotient;

        $solution = "**Решение:**\n\n"
            . "Порядок действий: умножение и деление (слева направо), "
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
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function shopFull(float $difficulty): MathProblem
    {
        $a = $this->rnd(10, 50);
        $b = $this->rnd(2, 9);
        $c = $this->rnd(2, 9);
        $d = $this->rnd(2, 9);
        $e = $this->rnd(2, 9);
        $product = $b * $c;
        $div = $d * $e;
        $quotient = $div / $e;
        $result = $a + $product - $quotient;

        $solution = "**Решение:**\n\n"
            . "Сначала умножение и деление, затем сложение и вычитание:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&$a + $b \\cdot $c - $div : $e \\\\",
                "&= $a + $product - $quotient \\\\",
                "&= $result",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $result);

        return new MathProblem(
            question: "В магазине было \\($a\\) кг, привезли \\($b\\) ящиков по \\($c\\) кг, "
                . "а затем \\($div\\) кг разложили по \\($e\\) пакетам и продали один пакет. "
                . "Вычислите: " . KaTeX::inline("$a + $b \\cdot $c - $div : $e") . ".",
            solution: $solution,
            answer: (string) $result,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function bracketsAndDivision(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 12);
        $b = $this->rnd(2, 12);
        $c = $this->rnd(2, 9);
        $sum = $a + $b;
        $result = $sum * $c;

        $solution = "**Решение:**\n\n"
            . "Сначала сумма в скобках, затем умножение:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&({$a} + {$b}) \\cdot $c \\\\",
                "&= $sum \\cdot $c \\\\",
                "&= $result",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $result);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("($a + $b) \\cdot $c"),
            solution: $solution,
            answer: (string) $result,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function twoBrackets(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 12);
        $b = $this->rnd(2, 12);
        $c = $this->rnd(2, 9);
        $d = $this->rnd(2, 9);
        $sum1 = $a + $b;
        $sum2 = $c + $d;
        $result = $sum1 * $sum2;

        $solution = "**Решение:**\n\n"
            . "Вычислим каждую скобку, затем перемножим:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&({$a} + {$b}) \\cdot ({$c} + {$d}) \\\\",
                "&= $sum1 \\cdot $sum2 \\\\",
                "&= $result",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $result);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("($a + $b) \\cdot ($c + $d)"),
            solution: $solution,
            answer: (string) $result,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
