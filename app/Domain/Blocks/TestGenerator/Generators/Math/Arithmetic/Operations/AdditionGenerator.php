<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\Arithmetic\Operations;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Сложение.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — однозначные числа
 *   [0.2–0.4) — двузначные
 *   [0.4–0.6) — трёхзначные
 *   [0.6–0.8) — четырёхзначные
 *   [0.8–1.0] — многозначные и комбинированные
 */
final class AdditionGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->chainSum($difficulty, $rng),
                fn () => $this->dailySum($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->columnSum($difficulty, $rng),
                fn () => $this->shopsSum($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->threeTerms($difficulty, $rng),
                fn () => $this->librarySum($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->bigColumn($difficulty, $rng),
                fn () => $this->fourNumbers($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->manyTerms($difficulty, $rng),
                fn () => $this->roundTens($difficulty, $rng),
            ])(),
        };
    }

    private function chainSum(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 1, 9);
        $b = $this->rnd($rng, 1, 9);
        $c = $this->rnd($rng, 1, 9);
        $sum = $a + $b + $c;

        $solution = "**Решение:**\n\n"
            . "Складываем по порядку:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a + $b + $c",
                (string) ($a + $b) . " + $c",
                (string) $sum,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $sum);

        return new MathProblem(
            question: "Вычислите сумму: " . KaTeX::inline("$a + $b + $c"),
            solution: $solution,
            answer: (string) $sum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function dailySum(float $difficulty, Randomizer $rng): MathProblem
    {
        $morning = $this->rnd($rng, 1, 9);
        $evening = $this->rnd($rng, 1, 9);
        $sum = $morning + $evening;

        $solution = "**Решение:**\n\n"
            . "За день всего пройдено:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$morning + $evening",
                (string) $sum,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $sum . " \\text{ км}");

        return new MathProblem(
            question: "Утром турист прошёл \\($morning\\) км, а вечером — \\($evening\\) км. "
                . "Сколько километров он прошёл за день?",
            solution: $solution,
            answer: (string) $sum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function columnSum(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 99);
        $b = $this->rnd($rng, 10, 99);
        $sum = $a + $b;

        $solution = "**Решение:**\n\n"
            . "Сложим в столбик (единицы под единицами, десятки под десятками):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a + $b",
                (string) $sum,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $sum);

        return new MathProblem(
            question: "Найдите сумму чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $sum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function shopsSum(float $difficulty, Randomizer $rng): MathProblem
    {
        $first = $this->rnd($rng, 10, 99);
        $second = $this->rnd($rng, 10, 99);
        $sum = $first + $second;

        $solution = "**Решение:**\n\n"
            . "Всего посетителей:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$first + $second",
                (string) $sum,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $sum . " \\text{ чел.}");

        return new MathProblem(
            question: "В первый магазин за день пришло \\($first\\) покупателей, "
                . "а во второй — \\($second\\). Сколько покупателей пришло в оба магазина вместе?",
            solution: $solution,
            answer: (string) $sum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function threeTerms(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 100, 999);
        $b = $this->rnd($rng, 100, 999);
        $c = $this->rnd($rng, 10, 99);
        $sum = $a + $b + $c;

        $solution = "**Решение:**\n\n"
            . "Сначала сложим первые два числа, затем прибавим третье:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a + $b + $c",
                (string) ($a + $b) . " + $c",
                (string) $sum,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $sum);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a + $b + $c"),
            solution: $solution,
            answer: (string) $sum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function librarySum(float $difficulty, Randomizer $rng): MathProblem
    {
        $novels = $this->rnd($rng, 100, 999);
        $poems = $this->rnd($rng, 100, 999);
        $sum = $novels + $poems;

        $solution = "**Решение:**\n\n"
            . "Всего книг в библиотеке:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$novels + $poems",
                (string) $sum,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $sum . " \\text{ книг}");

        return new MathProblem(
            question: "В библиотеке \\($novels\\) художественных книг и \\($poems\\) книг поэзии. "
                . "Сколько всего книг в библиотеке?",
            solution: $solution,
            answer: (string) $sum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function bigColumn(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 1000, 9999);
        $b = $this->rnd($rng, 1000, 9999);
        $sum = $a + $b;

        $solution = "**Решение:**\n\n"
            . "Сложим столбиком:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a + $b",
                (string) $sum,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $sum);

        return new MathProblem(
            question: "Чему равна сумма " . KaTeX::inline("$a + $b") . "?",
            solution: $solution,
            answer: (string) $sum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function fourNumbers(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 1000, 9999);
        $b = $this->rnd($rng, 1000, 9999);
        $c = $this->rnd($rng, 100, 999);
        $d = $this->rnd($rng, 10, 99);
        $sum = $a + $b + $c + $d;

        $solution = "**Решение:**\n\n"
            . "Группируем слагаемые и складываем:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a + $b + $c + $d",
                (string) ($a + $b) . " + " . ($c + $d),
                (string) $sum,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $sum);

        return new MathProblem(
            question: "Найдите сумму четырёх чисел: " . KaTeX::inline("$a + $b + $c + $d"),
            solution: $solution,
            answer: (string) $sum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function manyTerms(float $difficulty, Randomizer $rng): MathProblem
    {
        $terms = [];
        for ($i = 0; $i < 5; $i++) {
            $terms[] = $this->rnd($rng, 1000, 9999);
        }
        $sum = array_sum($terms);
        $expr = implode(' + ', $terms);

        $solution = "**Решение:**\n\n"
            . "Последовательно складываем все слагаемые:\n\n"
            . KaTeX::display(KaTeX::aligned([
                $expr,
                (string) $sum,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $sum);

        return new MathProblem(
            question: "Вычислите сумму пяти чисел: " . KaTeX::inline($expr),
            solution: $solution,
            answer: (string) $sum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function roundTens(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10, 90) * 100;
        $b = $this->rnd($rng, 10, 90) * 1000;
        $c = $this->rnd($rng, 10, 90) * 10;
        $sum = $a + $b + $c;

        $solution = "**Решение:**\n\n"
            . "Складываем круглые числа:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a + $b + $c",
                (string) $sum,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $sum);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a + $b + $c"),
            solution: $solution,
            answer: (string) $sum,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
