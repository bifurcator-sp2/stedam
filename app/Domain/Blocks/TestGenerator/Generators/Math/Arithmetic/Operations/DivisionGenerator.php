<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\Arithmetic\Operations;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Деление: без остатка (проверяем делением) и с остатком на старших уровнях.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — табличное деление
 *   [0.2–0.4) — деление на однозначное
 *   [0.4–0.6) — деление на двузначное
 *   [0.6–0.8) — деление без остатка трёхзначных
 *   [0.8–1.0] — деление с остатком
 */
final class DivisionGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->divideShort($difficulty, $rng),
                fn () => $this->shareCandy($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->divideColumn($difficulty, $rng),
                fn () => $this->pagesPerDay($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->divideTwoDigit($difficulty, $rng),
                fn () => $this->packBoxes($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->divideThreeDigit($difficulty, $rng),
                fn () => $this->rowsEvenly($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->withRemainder($difficulty, $rng),
                fn () => $this->bagsWithRest($difficulty, $rng),
            ])(),
        };
    }

    private function divideShort(float $difficulty, Randomizer $rng): MathProblem
    {
        $b = $this->rnd($rng, 2, 9);
        $q = $this->rnd($rng, 2, 9);
        $a = $b * $q;

        $solution = "**Решение:**\n\n"
            . "Подберём число, которое при умножении на \\($b\\) даёт \\($a\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\div $b",
                (string) $q,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $q);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a \\div $b"),
            solution: $solution,
            answer: (string) $q,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function shareCandy(float $difficulty, Randomizer $rng): MathProblem
    {
        $children = $this->rnd($rng, 2, 9);
        $each = $this->rnd($rng, 2, 9);
        $total = $children * $each;

        $solution = "**Решение:**\n\n"
            . "Каждому достанется:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$total \\div $children",
                (string) $each,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $each . " \\text{ конф.}");

        return new MathProblem(
            question: "\\($total\\) конфет разделили поровну между \\($children\\) детьми. "
                . "Сколько конфет получил каждый?",
            solution: $solution,
            answer: (string) $each,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function divideColumn(float $difficulty, Randomizer $rng): MathProblem
    {
        $b = $this->rnd($rng, 2, 9);
        $q = $this->rnd($rng, 10, 40);
        $a = $b * $q;

        $solution = "**Решение:**\n\n"
            . "Разделим в столбик:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\div $b",
                (string) $q,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $q);

        return new MathProblem(
            question: "Найдите частное чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $q,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function pagesPerDay(float $difficulty, Randomizer $rng): MathProblem
    {
        $days = $this->rnd($rng, 2, 9);
        $perDay = $this->rnd($rng, 10, 50);
        $total = $days * $perDay;

        $solution = "**Решение:**\n\n"
            . "Страниц в день:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$total \\div $days",
                (string) $perDay,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $perDay . " \\text{ стр.}");

        return new MathProblem(
            question: "За \\($days\\) дней прочитали \\($total\\) страниц, каждый день поровну. "
                . "Сколько страниц читали в день?",
            solution: $solution,
            answer: (string) $perDay,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function divideTwoDigit(float $difficulty, Randomizer $rng): MathProblem
    {
        $b = $this->rnd($rng, 10, 30);
        $q = $this->rnd($rng, 2, 9);
        $a = $b * $q;

        $solution = "**Решение:**\n\n"
            . "Разделим, подбирая частное:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\div $b",
                (string) $q,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $q);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a \\div $b"),
            solution: $solution,
            answer: (string) $q,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function packBoxes(float $difficulty, Randomizer $rng): MathProblem
    {
        $perBox = $this->rnd($rng, 10, 30);
        $boxes = $this->rnd($rng, 2, 9);
        $total = $perBox * $boxes;

        $solution = "**Решение:**\n\n"
            . "Получилось коробок:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$total \\div $perBox",
                (string) $boxes,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $boxes . " \\text{ кор.}");

        return new MathProblem(
            question: "\\($total\\) предметов разложили по \\($perBox\\) штук в коробку. "
                . "Сколько получилось коробок?",
            solution: $solution,
            answer: (string) $boxes,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function divideThreeDigit(float $difficulty, Randomizer $rng): MathProblem
    {
        $b = $this->rnd($rng, 2, 9);
        $q = $this->rnd($rng, 20, 100);
        $a = $b * $q;

        $solution = "**Решение:**\n\n"
            . "Разделим в столбик:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\div $b",
                (string) $q,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $q);

        return new MathProblem(
            question: "Чему равно частное " . KaTeX::inline("$a \\div $b") . "?",
            solution: $solution,
            answer: (string) $q,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function rowsEvenly(float $difficulty, Randomizer $rng): MathProblem
    {
        $rows = $this->rnd($rng, 2, 9);
        $perRow = $this->rnd($rng, 20, 100);
        $total = $rows * $perRow;

        $solution = "**Решение:**\n\n"
            . "В каждом ряду:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$total \\div $rows",
                (string) $perRow,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $perRow . " \\text{ мест}");

        return new MathProblem(
            question: "\\($total\\) стульев расставили в \\($rows\\) одинаковых рядов. "
                . "Сколько стульев в каждом ряду?",
            solution: $solution,
            answer: (string) $perRow,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function withRemainder(float $difficulty, Randomizer $rng): MathProblem
    {
        $divisor = $this->rnd($rng, 2, 20);
        $quotient = $this->rnd($rng, 1, 50);
        $remainder = $this->rnd($rng, 0, $divisor - 1);
        $dividend = $divisor * $quotient + $remainder;

        $answer = "неполное частное $quotient, остаток $remainder";

        $solution = "**Решение:**\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$dividend \\div $divisor",
                "$divisor \\cdot $quotient = " . ($divisor * $quotient),
                "$dividend - " . ($divisor * $quotient) . " = $remainder",
                "\\Rightarrow\\; $quotient \\text{ (ост. } $remainder\\text{)}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($answer);

        return new MathProblem(
            question: "Разделите с остатком: " . KaTeX::inline("$dividend \\div $divisor"),
            solution: $solution,
            answer: $answer,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function bagsWithRest(float $difficulty, Randomizer $rng): MathProblem
    {
        $perBag = $this->rnd($rng, 3, 15);
        $bags = $this->rnd($rng, 2, 30);
        $remainder = $this->rnd($rng, 1, $perBag - 1);
        $total = $perBag * $bags + $remainder;

        $answer = "полных пакетов $bags, останется $remainder";

        $solution = "**Решение:**\n\n"
            . "Найдём неполное частное и остаток:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$total \\div $perBag",
                "$perBag \\cdot $bags = " . ($perBag * $bags),
                "$total - " . ($perBag * $bags) . " = $remainder",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($answer);

        return new MathProblem(
            question: "\\($total\\) кг крупы расфасовывают в пакеты по \\($perBag\\) кг. "
                . "Сколько получится полных пакетов и сколько кг останется?",
            solution: $solution,
            answer: $answer,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
