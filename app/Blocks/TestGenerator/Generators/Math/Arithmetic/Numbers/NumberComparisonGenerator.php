<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Numbers;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Сравнение чисел: поставить знак <, > или =.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — однозначные числа
 *   [0.2–0.4) — двузначные числа
 *   [0.4–0.6) — числа с разным числом знаков
 *   [0.6–0.8) — трёх- и четырёхзначные
 *   [0.8–1.0] — большие числа и возможное равенство
 */
final class NumberComparisonGenerator extends GeneratorBase
{
    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick([
                fn () => $this->compareDigit($difficulty),
                fn () => $this->compareScore($difficulty),
            ])(),
            $difficulty < 0.4 => $this->pick([
                fn () => $this->compareTwoDigit($difficulty),
                fn () => $this->compareAge($difficulty),
            ])(),
            $difficulty < 0.6 => $this->pick([
                fn () => $this->compareDifferentLength($difficulty),
                fn () => $this->comparePrice($difficulty),
            ])(),
            $difficulty < 0.8 => $this->pick([
                fn () => $this->compareBig($difficulty),
                fn () => $this->compareDistance($difficulty),
            ])(),
            default => $this->pick([
                fn () => $this->compareGreat($difficulty),
                fn () => $this->compareEqual($difficulty),
            ])(),
        };
    }

    private function sign(int $a, int $b): string
    {
        return match ($a <=> $b) { 1 => '>', -1 => '<', default => '=' };
    }

    private function compareDigit(float $difficulty): MathProblem
    {
        $a = $this->rnd(1, 9);
        $b = $this->rnd(1, 9);
        $symbol = $this->sign($a, $b);

        $solution = "**Решение:**\n\n"
            . "Сравниваем два однозначных числа:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$a \\quad ? \\quad $b",
                "$a \\; $symbol \\; $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Сравните числа \\($a\\) и \\($b\\). Поставьте знак \\(<\\), \\(>\\) или \\(=\\).",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function compareScore(float $difficulty): MathProblem
    {
        $a = $this->rnd(1, 9);
        $b = $this->rnd(1, 9);
        $symbol = $this->sign($a, $b);

        $solution = "**Решение:**\n\n"
            . "У кого больше очков, тот и победил:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$a \\quad ? \\quad $b",
                "$a \\; $symbol \\; $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Первый игрок набрал \\($a\\) очков, второй — \\($b\\). "
                . "Поставьте знак между числами очков: \\($a \\;?\\; $b\\).",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function compareTwoDigit(float $difficulty): MathProblem
    {
        $a = $this->rnd(10, 99);
        $b = $this->rnd(10, 99);
        $symbol = $this->sign($a, $b);

        $solution = "**Решение:**\n\n"
            . "Сравниваем десятки, затем единицы:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$a \\quad ? \\quad $b",
                "$a \\; $symbol \\; $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Сравните числа \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function compareAge(float $difficulty): MathProblem
    {
        $a = $this->rnd(10, 99);
        $b = $this->rnd(10, 99);
        $symbol = $this->sign($a, $b);

        $solution = "**Решение:**\n\n"
            . "Сравниваем возраст:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$a \\quad ? \\quad $b",
                "$a \\; $symbol \\; $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Брату \\($a\\) лет, сестре \\($b\\) лет. Поставьте знак между их возрастами: \\($a \\;?\\; $b\\).",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function compareDifferentLength(float $difficulty): MathProblem
    {
        $a = $this->rnd(10, 99);
        $b = $this->rnd(100, 999);
        $symbol = $this->sign($a, $b);

        $solution = "**Решение:**\n\n"
            . "Число с бо́льшим количеством знаков больше:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$a \\quad ? \\quad $b",
                "$a \\; $symbol \\; $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Сравните числа \\($a\\) и \\($b\\). Поставьте знак \\(<\\), \\(>\\) или \\(=\\).",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function comparePrice(float $difficulty): MathProblem
    {
        $a = $this->rnd(10, 99);
        $b = $this->rnd(100, 999);
        $symbol = $this->sign($a, $b);

        $solution = "**Решение:**\n\n"
            . "Сравниваем стоимости:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$a \\quad ? \\quad $b",
                "$a \\; $symbol \\; $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Товар стоит \\($a\\) ₽, а другой — \\($b\\) ₽. Поставьте знак: \\($a \\;?\\; $b\\).",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function compareBig(float $difficulty): MathProblem
    {
        $a = $this->rnd(1000, 9999);
        $b = $this->rnd(1000, 9999);
        $symbol = $this->sign($a, $b);

        $solution = "**Решение:**\n\n"
            . "Сравниваем разряд за разрядом:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$a \\quad ? \\quad $b",
                "$a \\; $symbol \\; $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Сравните числа \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function compareDistance(float $difficulty): MathProblem
    {
        $a = $this->rnd(1000, 9999);
        $b = $this->rnd(1000, 9999);
        $symbol = $this->sign($a, $b);

        $solution = "**Решение:**\n\n"
            . "Сравниваем километраж:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$a \\quad ? \\quad $b",
                "$a \\; $symbol \\; $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Первый путь — \\($a\\) км, второй — \\($b\\) км. Поставьте знак: \\($a \\;?\\; $b\\).",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function compareGreat(float $difficulty): MathProblem
    {
        $a = $this->rnd(10000, 99999);
        $b = $this->rnd(10000, 99999);
        $symbol = $this->sign($a, $b);

        $solution = "**Решение:**\n\n"
            . "Сравниваем многозначные числа:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$a \\quad ? \\quad $b",
                "$a \\; $symbol \\; $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Сравните числа \\($a\\) и \\($b\\). Поставьте знак \\(<\\), \\(>\\) или \\(=\\).",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function compareEqual(float $difficulty): MathProblem
    {
        $a = $this->rnd(1000, 9999);
        $b = random_int(0, 1) === 1 ? $a : $a + $this->rnd(1, 999);
        $symbol = $this->sign($a, $b);

        $solution = "**Решение:**\n\n"
            . "Сравниваем числа по разрядам:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "$a \\quad ? \\quad $b",
                "$a \\; $symbol \\; $b",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($symbol);

        return new MathProblem(
            question: "Сравните числа \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: $symbol,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
