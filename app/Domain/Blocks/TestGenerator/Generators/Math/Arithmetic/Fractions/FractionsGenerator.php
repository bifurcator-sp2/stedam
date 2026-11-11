<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\Arithmetic\Fractions;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Дроби: сравнение, сложение с одинаковыми и разными знаменателями.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — сравнение дробей с одинаковым знаменателем
 *   [0.2–0.4) — сложение с одинаковым знаменателем
 *   [0.4–0.6) — сравнение дробей с разными знаменателями
 *   [0.6–0.8) — сложение с разными знаменателями (один кратен другому)
 *   [0.8–1.0] — сложение с разными знаменателями (взаимно простые)
 */
final class FractionsGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->compareSameDen($difficulty, $rng),
                fn () => $this->comparePitsa($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->addSameDen($difficulty, $rng),
                fn () => $this->addPies($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->compareDiffDen($difficulty, $rng),
                fn () => $this->compareNapolnenie($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->addMultipleDen($difficulty, $rng),
                fn () => $this->addJuice($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->addCoprimeDen($difficulty, $rng),
                fn () => $this->addTanks($difficulty, $rng),
            ])(),
        };
    }

    private function symbol(int $left, int $right): string
    {
        return match ($left <=> $right) { 1 => '>', -1 => '<', default => '=' };
    }

    private function compareSameDen(float $difficulty, Randomizer $rng): MathProblem
    {
        $d = $this->rnd($rng, 3, 12);
        $a = $this->rnd($rng, 1, $d - 1);
        $b = $this->rnd($rng, 1, $d - 1);
        $symbol = $this->symbol($a, $b);

        $solution = "**Решение:**\n\n"
            . "При одинаковых знаменателях больше та дробь, у которой больше числитель:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a}{$d} \\; $symbol \\; \\dfrac{$b}{$d}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Сравните дроби " . KaTeX::inline("\\dfrac{$a}{$d}") . " и "
                . KaTeX::inline("\\dfrac{$b}{$d}") . ".",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function comparePitsa(float $difficulty, Randomizer $rng): MathProblem
    {
        $d = $this->rnd($rng, 4, 12);
        $a = $this->rnd($rng, 1, $d - 1);
        $b = $this->rnd($rng, 1, $d - 1);
        $symbol = $this->symbol($a, $b);

        $solution = "**Решение:**\n\n"
            . "Пиццу разделили на \\($d\\) кусков, сравним количество кусков:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a}{$d} \\; $symbol \\; \\dfrac{$b}{$d}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "У Пети " . KaTeX::inline("\\dfrac{$a}{$d}") . " пиццы, у Васи "
                . KaTeX::inline("\\dfrac{$b}{$d}") . " такой же пиццы. У кого больше? "
                . "Поставьте знак между дробями.",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function addSameDen(float $difficulty, Randomizer $rng): MathProblem
    {
        $d = $this->rnd($rng, 3, 12);
        $a = $this->rnd($rng, 1, $d - 1);
        $b = $this->rnd($rng, 1, $d - $a);
        $sum = $a + $b;

        $solution = "**Решение:**\n\n"
            . "Складываем числители, знаменатель оставляем прежним:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a}{$d} + \\dfrac{$b}{$d}",
                "\\dfrac{$sum}{$d}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$sum}{$d}");

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("\\dfrac{$a}{$d} + \\dfrac{$b}{$d}"),
            solution: $solution,
            answer: "\\dfrac{$sum}{$d}",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function addPies(float $difficulty, Randomizer $rng): MathProblem
    {
        $d = $this->rnd($rng, 4, 12);
        $a = $this->rnd($rng, 1, $d - 1);
        $b = $this->rnd($rng, 1, $d - $a);
        $sum = $a + $b;

        $solution = "**Решение:**\n\n"
            . "Знаменатель одинаковый, поэтому складываем куски:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a}{$d} + \\dfrac{$b}{$d}",
                "\\dfrac{$sum}{$d}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$sum}{$d}");

        return new MathProblem(
            question: "Съели " . KaTeX::inline("\\dfrac{$a}{$d}") . " пирога, потом ещё "
                . KaTeX::inline("\\dfrac{$b}{$d}") . ". Сколько всего пирога съели?",
            solution: $solution,
            answer: "\\dfrac{$sum}{$d}",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function compareDiffDen(float $difficulty, Randomizer $rng): MathProblem
    {
        $b = $this->rnd($rng, 2, 9);
        $a = $this->rnd($rng, 1, $b - 1);
        $d = $this->rnd($rng, 2, 9);
        $c = $this->rnd($rng, 1, $d - 1);

        $left  = $a * $d;
        $right = $c * $b;
        $symbol = $this->symbol($left, $right);

        $common = $b * $d;

        $solution = "**Решение:**\n\n"
            . "Приведём дроби к общему знаменателю \\($b \\cdot $d = $common\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a}{$b} = \\dfrac{$left}{$common}",
                "\\dfrac{$c}{$d} = \\dfrac{$right}{$common}",
            ]))
            . "\n\nСравниваем числители, получаем: "
            . KaTeX::inline("\\dfrac{$a}{$b} $symbol \\dfrac{$c}{$d}")
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Сравните дроби " . KaTeX::inline("\\dfrac{$a}{$b}") . " и "
                . KaTeX::inline("\\dfrac{$c}{$d}") . ".",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function compareNapolnenie(float $difficulty, Randomizer $rng): MathProblem
    {
        $b = $this->rnd($rng, 2, 9);
        $a = $this->rnd($rng, 1, $b - 1);
        $d = $this->rnd($rng, 2, 9);
        $c = $this->rnd($rng, 1, $d - 1);

        $left  = $a * $d;
        $right = $c * $b;
        $symbol = $this->symbol($left, $right);
        $common = $b * $d;

        $solution = "**Решение:**\n\n"
            . "Общий знаменатель \\($common\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a}{$b} = \\dfrac{$left}{$common}",
                "\\dfrac{$c}{$d} = \\dfrac{$right}{$common}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$a}{$b} $symbol \\dfrac{$c}{$d}");

        return new MathProblem(
            question: "Два бака заполнены на " . KaTeX::inline("\\dfrac{$a}{$b}") . " и "
                . KaTeX::inline("\\dfrac{$c}{$d}") . ". Какой бак заполнен больше? Поставьте знак.",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function addMultipleDen(float $difficulty, Randomizer $rng): MathProblem
    {
        $b1 = $this->rnd($rng, 2, 5);
        $b2 = $b1 * $this->rnd($rng, 2, 4);
        $a1 = $this->rnd($rng, 1, $b1 - 1);
        $a2 = $this->rnd($rng, 1, $b2 - 1);

        $common = $b2;
        $n1 = $a1 * intdiv($b2, $b1);
        $n2 = $a2;
        $sum = $n1 + $n2;

        $solution = "**Решение:**\n\n"
            . "Общий знаменатель — большее число \\($common\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a1}{$b1} + \\dfrac{$a2}{$b2}",
                "\\dfrac{$n1}{$common} + \\dfrac{$n2}{$common}",
                "\\dfrac{$sum}{$common}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$sum}{$common}");

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("\\dfrac{$a1}{$b1} + \\dfrac{$a2}{$b2}"),
            solution: $solution,
            answer: "\\dfrac{$sum}{$common}",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function addJuice(float $difficulty, Randomizer $rng): MathProblem
    {
        $b1 = $this->rnd($rng, 2, 4);
        $b2 = $b1 * $this->rnd($rng, 2, 4);
        $a1 = $this->rnd($rng, 1, $b1 - 1);
        $a2 = $this->rnd($rng, 1, $b2 - 1);
        $common = $b2;
        $n1 = $a1 * intdiv($b2, $b1);
        $n2 = $a2;
        $sum = $n1 + $n2;

        $solution = "**Решение:**\n\n"
            . "Общий знаменатель \\($common\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a1}{$b1} + \\dfrac{$a2}{$b2}",
                "\\dfrac{$n1}{$common} + \\dfrac{$n2}{$common}",
                "\\dfrac{$sum}{$common}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$sum}{$common}");

        return new MathProblem(
            question: "В первый стакан налили " . KaTeX::inline("\\dfrac{$a1}{$b1}") . " л сока, "
                . "во второй — " . KaTeX::inline("\\dfrac{$a2}{$b2}") . " л. Сколько сока всего?",
            solution: $solution,
            answer: "\\dfrac{$sum}{$common}",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function addCoprimeDen(float $difficulty, Randomizer $rng): MathProblem
    {
        $b1 = $this->rnd($rng, 3, 9);
        $b2 = $this->rnd($rng, 3, 9);
        while ($b1 === $b2) {
            $b2 = $this->rnd($rng, 3, 9);
        }
        $a1 = $this->rnd($rng, 1, $b1 - 1);
        $a2 = $this->rnd($rng, 1, $b2 - 1);

        $common = $b1 * $b2;
        $n1 = $a1 * $b2;
        $n2 = $a2 * $b1;
        $sum = $n1 + $n2;

        $solution = "**Решение:**\n\n"
            . "Общий знаменатель — произведение знаменателей: \\($common = $b1 \\cdot $b2\\).\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a1}{$b1} + \\dfrac{$a2}{$b2}",
                "\\dfrac{$n1}{$common} + \\dfrac{$n2}{$common}",
                "\\dfrac{$sum}{$common}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$sum}{$common}");

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("\\dfrac{$a1}{$b1} + \\dfrac{$a2}{$b2}"),
            solution: $solution,
            answer: "\\dfrac{$sum}{$common}",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function addTanks(float $difficulty, Randomizer $rng): MathProblem
    {
        $b1 = $this->rnd($rng, 3, 9);
        $b2 = $this->rnd($rng, 3, 9);
        while ($b1 === $b2) {
            $b2 = $this->rnd($rng, 3, 9);
        }
        $a1 = $this->rnd($rng, 1, $b1 - 1);
        $a2 = $this->rnd($rng, 1, $b2 - 1);
        $common = $b1 * $b2;
        $n1 = $a1 * $b2;
        $n2 = $a2 * $b1;
        $sum = $n1 + $n2;

        $solution = "**Решение:**\n\n"
            . "Приведём к общему знаменателю \\($common\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$a1}{$b1} + \\dfrac{$a2}{$b2}",
                "\\dfrac{$n1}{$common} + \\dfrac{$n2}{$common}",
                "\\dfrac{$sum}{$common}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("\\dfrac{$sum}{$common}");

        return new MathProblem(
            question: "Первый бак наполнен на " . KaTeX::inline("\\dfrac{$a1}{$b1}") . ", "
                . "второй — на " . KaTeX::inline("\\dfrac{$a2}{$b2}") . ". Сложите эти доли.",
            solution: $solution,
            answer: "\\dfrac{$sum}{$common}",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
