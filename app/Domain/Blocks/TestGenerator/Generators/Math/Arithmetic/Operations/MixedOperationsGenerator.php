<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\Arithmetic\Operations;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Смешанные выражения: несколько действий.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — a + b · c
 *   [0.2–0.4) — (a + b) · c
 *   [0.4–0.6) — a + b · c − d
 *   [0.6–0.8) — a + b · c − d : e
 *   [0.8–1.0] — выражение со скобками и делением
 */
final class MixedOperationsGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->addMul($difficulty, $rng),
                fn () => $this->buyAddMul($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->withBrackets($difficulty, $rng),
                fn () => $this->groupBrackets($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->addMulSub($difficulty, $rng),
                fn () => $this->changeMulSub($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->full($difficulty, $rng),
                fn () => $this->shopFull($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->bracketsAndDivision($difficulty, $rng),
                fn () => $this->twoBrackets($difficulty, $rng),
            ])(),
        };
    }

    private function addMul(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 20);
        $b = $this->rnd($rng, 2, 9);
        $c = $this->rnd($rng, 2, 9);

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

    private function buyAddMul(float $difficulty, Randomizer $rng): MathProblem
    {
        $base = $this->rnd($rng, 2, 20);
        $price = $this->rnd($rng, 2, 9);
        $count = $this->rnd($rng, 2, 9);
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

    private function withBrackets(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 15);
        $b = $this->rnd($rng, 2, 15);
        $c = $this->rnd($rng, 2, 9);

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

    private function groupBrackets(float $difficulty, Randomizer $rng): MathProblem
    {
        $x = $this->rnd($rng, 2, 15);
        $y = $this->rnd($rng, 2, 15);
        $k = $this->rnd($rng, 2, 9);
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

    private function addMulSub(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 50);
        $b = $this->rnd($rng, 2, 9);
        $c = $this->rnd($rng, 2, 9);
        $d = $this->rnd($rng, 2, 9);

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

    private function changeMulSub(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 50);
        $b = $this->rnd($rng, 2, 9);
        $c = $this->rnd($rng, 2, 9);
        $d = $this->rnd($rng, 2, $b * $c);
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

    private function full(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 50);
        $b = $this->rnd($rng, 2, 9);
        $c = $this->rnd($rng, 2, 9);
        $d = $this->rnd($rng, 2, 9);
        $e = $this->rnd($rng, 2, 9);

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

    private function shopFull(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 50);
        $b = $this->rnd($rng, 2, 9);
        $c = $this->rnd($rng, 2, 9);
        $d = $this->rnd($rng, 2, 9);
        $e = $this->rnd($rng, 2, 9);
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

    private function bracketsAndDivision(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 12);
        $b = $this->rnd($rng, 2, 12);
        $c = $this->rnd($rng, 2, 9);
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

    private function twoBrackets(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 12);
        $b = $this->rnd($rng, 2, 12);
        $c = $this->rnd($rng, 2, 9);
        $d = $this->rnd($rng, 2, 9);
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
