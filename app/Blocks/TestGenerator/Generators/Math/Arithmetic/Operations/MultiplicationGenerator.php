<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Operations;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Умножение: произведение неотрицательных целых.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — табличное умножение
 *   [0.2–0.4) — двузначное на однозначное
 *   [0.4–0.6) — двузначное на двузначное
 *   [0.6–0.8) — трёхзначное на двузначное
 *   [0.8–1.0] — произведение нескольких множителей
 */
final class MultiplicationGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->timesShort($difficulty, $rng),
                fn () => $this->boxRows($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->timesColumn($difficulty, $rng),
                fn () => $this->weeklyOutput($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->timesDouble($difficulty, $rng),
                fn () => $this->tileArea($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->timesTriple($difficulty, $rng),
                fn () => $this->booksTotal($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->timesThree($difficulty, $rng),
                fn () => $this->powerOfTwo($difficulty, $rng),
            ])(),
        };
    }

    private function timesShort(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 9);
        $b = $this->rnd($rng, 2, 9);
        $product = $a * $b;

        $solution = "**Решение:**\n\n"
            . "По таблице умножения:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\cdot $b",
                (string) $product,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $product);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a \\cdot $b"),
            solution: $solution,
            answer: (string) $product,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function boxRows(float $difficulty, Randomizer $rng): MathProblem
    {
        $rows = $this->rnd($rng, 2, 9);
        $cols = $this->rnd($rng, 2, 9);
        $total = $rows * $cols;

        $solution = "**Решение:**\n\n"
            . "Всего карандашей:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$rows \\cdot $cols",
                (string) $total,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total . " \\text{ шт.}");

        return new MathProblem(
            question: "В коробке \\($rows\\) рядов по \\($cols\\) карандашей. "
                . "Сколько всего карандашей в коробке?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function timesColumn(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 99);
        $b = $this->rnd($rng, 2, 9);
        $product = $a * $b;

        $solution = "**Решение:**\n\n"
            . "Умножим в столбик:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\cdot $b",
                (string) $product,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $product);

        return new MathProblem(
            question: "Найдите произведение чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $product,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function weeklyOutput(float $difficulty, Randomizer $rng): MathProblem
    {
        $perDay = $this->rnd($rng, 10, 99);
        $days = $this->rnd($rng, 2, 9);
        $total = $perDay * $days;

        $solution = "**Решение:**\n\n"
            . "Всего деталей за смену:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$perDay \\cdot $days",
                (string) $total,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total . " \\text{ дет.}");

        return new MathProblem(
            question: "Мастер делает \\($perDay\\) деталей в час и работал \\($days\\) часов. "
                . "Сколько деталей он сделал?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function timesDouble(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 99);
        $b = $this->rnd($rng, 10, 99);
        $product = $a * $b;

        $solution = "**Решение:**\n\n"
            . "Умножаем в столбик (единицы и десятки отдельно):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\cdot $b",
                (string) $product,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $product);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a \\cdot $b"),
            solution: $solution,
            answer: (string) $product,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function tileArea(float $difficulty, Randomizer $rng): MathProblem
    {
        $width = $this->rnd($rng, 10, 99);
        $height = $this->rnd($rng, 10, 99);
        $area = $width * $height;

        $solution = "**Решение:**\n\n"
            . "Площадь участка:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$width \\cdot $height",
                (string) $area,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $area . " \\text{ м}^2");

        return new MathProblem(
            question: "Прямоугольный участок имеет размеры \\($width\\) м на \\($height\\) м. "
                . "Чему равна его площадь в квадратных метрах?",
            solution: $solution,
            answer: (string) $area,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function timesTriple(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 100, 999);
        $b = $this->rnd($rng, 10, 99);
        $product = $a * $b;

        $solution = "**Решение:**\n\n"
            . "Умножим в столбик:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\cdot $b",
                (string) $product,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $product);

        return new MathProblem(
            question: "Вычислите произведение: " . KaTeX::inline("$a \\cdot $b"),
            solution: $solution,
            answer: (string) $product,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function booksTotal(float $difficulty, Randomizer $rng): MathProblem
    {
        $shelves = $this->rnd($rng, 10, 99);
        $perShelf = $this->rnd($rng, 100, 999);
        $total = $shelves * $perShelf;

        $solution = "**Решение:**\n\n"
            . "Всего книг на складе:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$shelves \\cdot $perShelf",
                (string) $total,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        return new MathProblem(
            question: "На складе \\($shelves\\) стеллажей, на каждом \\($perShelf\\) книг. "
                . "Сколько всего книг на складе?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function timesThree(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 50);
        $b = $this->rnd($rng, 10, 50);
        $c = $this->rnd($rng, 2, 9);
        $product = $a * $b * $c;

        $solution = "**Решение:**\n\n"
            . "Умножим по порядку:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\cdot $b \\cdot $c",
                (string) ($a * $b) . " \\cdot $c",
                (string) $product,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $product);

        return new MathProblem(
            question: "Вычислите произведение: " . KaTeX::inline("$a \\cdot $b \\cdot $c"),
            solution: $solution,
            answer: (string) $product,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function powerOfTwo(float $difficulty, Randomizer $rng): MathProblem
    {
        $base = $this->rnd($rng, 2, 5);
        $power = $this->rnd($rng, 3, 5);
        $product = $base ** $power;
        $chain = implode(' \\cdot ', array_fill(0, $power, (string) $base));

        $solution = "**Решение:**\n\n"
            . "Возведение в степень — это повторное умножение:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$base^{$power} = $chain",
                (string) $product,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $product);

        return new MathProblem(
            question: "Вычислите степень: " . KaTeX::inline("$base^{$power}"),
            solution: $solution,
            answer: (string) $product,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
