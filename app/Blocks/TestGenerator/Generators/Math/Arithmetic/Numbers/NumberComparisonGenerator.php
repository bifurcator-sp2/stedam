<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Numbers;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

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
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->compareDigit($difficulty, $rng),
                fn () => $this->compareScore($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->compareTwoDigit($difficulty, $rng),
                fn () => $this->compareAge($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->compareDifferentLength($difficulty, $rng),
                fn () => $this->comparePrice($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->compareBig($difficulty, $rng),
                fn () => $this->compareDistance($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->compareGreat($difficulty, $rng),
                fn () => $this->compareEqual($difficulty, $rng),
            ])(),
        };
    }

    private function sign(int $a, int $b): string
    {
        return match ($a <=> $b) { 1 => '>', -1 => '<', default => '=' };
    }

    private function compareDigit(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 1, 9);
        $b = $this->rnd($rng, 1, 9);
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

    private function compareScore(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 1, 9);
        $b = $this->rnd($rng, 1, 9);
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

    private function compareTwoDigit(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 99);
        $b = $this->rnd($rng, 10, 99);
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

    private function compareAge(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 99);
        $b = $this->rnd($rng, 10, 99);
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

    private function compareDifferentLength(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 99);
        $b = $this->rnd($rng, 100, 999);
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

    private function comparePrice(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 99);
        $b = $this->rnd($rng, 100, 999);
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

    private function compareBig(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 1000, 9999);
        $b = $this->rnd($rng, 1000, 9999);
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

    private function compareDistance(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 1000, 9999);
        $b = $this->rnd($rng, 1000, 9999);
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

    private function compareGreat(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10000, 99999);
        $b = $this->rnd($rng, 10000, 99999);
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

    private function compareEqual(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 1000, 9999);
        $b = $this->rnd($rng, 0, 1) === 1 ? $a : $a + $this->rnd($rng, 1, 999);
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
