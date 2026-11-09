<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Fractions;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Проценты: нахождение части, целого и процентного отношения.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — найти часть от числа (круглые проценты)
 *   [0.2–0.4) — найти часть от числа (скидка)
 *   [0.4–0.6) — найти целое по части
 *   [0.6–0.8) — найти процентное отношение
 *   [0.8–1.0] — целое по части (некруглый процент)
 */
final class PercentagesGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->findPart($difficulty, $rng),
                fn () => $this->depositInterest($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->findDiscount($difficulty, $rng),
                fn () => $this->findPartSale($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->findWhole($difficulty, $rng),
                fn () => $this->findWholeClass($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->findRatio($difficulty, $rng),
                fn () => $this->findRatioVotes($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->findWholeOdd($difficulty, $rng),
                fn () => $this->findWholeBooks($difficulty, $rng),
            ])(),
        };
    }

    private function findPart(float $difficulty, Randomizer $rng): MathProblem
    {
        $p = $this->pick($rng, [5, 10, 20, 25, 50]);
        $n = $this->rnd($rng, 10, 400);
        $value = $n * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Процент — это сотые доли, найдём \\($p\\%\\) от числа \\($n\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$n \\cdot \\dfrac{$p}{100}",
                $this->num($value),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($value));

        return new MathProblem(
            question: "Найдите \\($p\\%\\) от числа \\($n\\).",
            solution: $solution,
            answer: $this->num($value),
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function depositInterest(float $difficulty, Randomizer $rng): MathProblem
    {
        $p = $this->pick($rng, [5, 10, 20, 25]);
        $n = $this->rnd($rng, 10, 40) * 100;
        $value = $n * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Годовые проценты по вкладу:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$n \\cdot \\dfrac{$p}{100}",
                $this->num($value),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($value) . " \\text{ ₽}");

        return new MathProblem(
            question: "Вклад \\($n\\) ₽ под \\($p\\%\\) годовых. Сколько денег начислят за год?",
            solution: $solution,
            answer: $this->num($value),
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findDiscount(float $difficulty, Randomizer $rng): MathProblem
    {
        $p = $this->pick($rng, [10, 15, 20, 25, 30]);
        $n = $this->rnd($rng, 10, 40) * 10;
        $discount = $n * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Размер скидки:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$n \\cdot \\dfrac{$p}{100}",
                $this->num($discount),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($discount) . " \\text{ ₽}");

        return new MathProblem(
            question: "Товар стоит \\($n\\) ₽, скидка \\($p\\%\\). Сколько рублей составляет скидка?",
            solution: $solution,
            answer: $this->num($discount),
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findPartSale(float $difficulty, Randomizer $rng): MathProblem
    {
        $p = $this->pick($rng, [10, 15, 20, 25, 30]);
        $n = $this->rnd($rng, 10, 40) * 10;
        $rest = $n - $n * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Найдём размер скидки, затем вычтем из цены:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$n - $n \\cdot \\dfrac{$p}{100}",
                $this->num($rest),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($rest) . " \\text{ ₽}");

        return new MathProblem(
            question: "Товар стоит \\($n\\) ₽. На него действует скидка \\($p\\%\\). "
                . "Сколько будет стоить товар после скидки?",
            solution: $solution,
            answer: $this->num($rest),
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findWhole(float $difficulty, Randomizer $rng): MathProblem
    {
        $p = $this->pick($rng, [10, 20, 25, 40, 50]);
        $whole = $this->rnd($rng, 20, 400) * 5;
        $part  = $whole * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Если \\($p\\%\\) — это " . $this->num($part) . ", то целое:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $this->num($part) . "}{$p} \\cdot 100",
                (string) $whole,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $whole);

        return new MathProblem(
            question: "\\($p\\%\\) числа равны " . $this->num($part) . ". Найдите само число.",
            solution: $solution,
            answer: (string) $whole,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findWholeClass(float $difficulty, Randomizer $rng): MathProblem
    {
        $p = $this->pick($rng, [20, 25, 50]);
        $whole = $this->rnd($rng, 4, 40) * 4;
        $part  = $whole * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Учеников с пятёрками \\($p\\%\\) — это " . $this->num($part) . ". Всего учеников:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $this->num($part) . "}{$p} \\cdot 100",
                (string) $whole,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $whole . " \\text{ уч.}");

        return new MathProblem(
            question: "В классе \\($p\\%\\) учеников учатся на «5», это \\($part\\) человек. "
                . "Сколько всего учеников в классе?",
            solution: $solution,
            answer: (string) $whole,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findRatio(float $difficulty, Randomizer $rng): MathProblem
    {
        $part  = $this->rnd($rng, 2, 40);
        $whole = $part * $this->rnd($rng, 2, 10);
        $pct   = $part / $whole * 100;

        $solution = "**Решение:**\n\n"
            . "Процентное отношение — часть, делённая на целое, умноженная на 100\\%:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$part}{$whole} \\cdot 100\\%",
                $this->num($pct) . "\\%",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($pct) . "\\%");

        return new MathProblem(
            question: "Сколько процентов число \\($part\\) составляет от числа \\($whole\\)?",
            solution: $solution,
            answer: $this->num($pct) . '%',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findRatioVotes(float $difficulty, Randomizer $rng): MathProblem
    {
        $part  = $this->rnd($rng, 10, 50);
        $whole = $part * $this->rnd($rng, 2, 8);
        $pct   = $part / $whole * 100;

        $solution = "**Решение:**\n\n"
            . "Доля проголосовавших за кандидата:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$part}{$whole} \\cdot 100\\%",
                $this->num($pct) . "\\%",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($pct) . "\\%");

        return new MathProblem(
            question: "Из \\($whole\\) избирателей за кандидата проголосовали \\($part\\). "
                . "Сколько процентов голосов он получил?",
            solution: $solution,
            answer: $this->num($pct) . '%',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findWholeOdd(float $difficulty, Randomizer $rng): MathProblem
    {
        $p = $this->pick($rng, [12, 15, 18, 24, 36]);
        $whole = $p * 5 * $this->rnd($rng, 2, 10);
        $part  = $whole * $p / 100;

        $solution = "**Решение:**\n\n"
            . "\\($p\\%\\) — это " . $this->num($part) . ". Найдём целое:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $this->num($part) . "}{$p} \\cdot 100",
                (string) $whole,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $whole);

        return new MathProblem(
            question: "\\($p\\%\\) некоторого числа равны " . $this->num($part) . ". "
                . "Чему равно это число?",
            solution: $solution,
            answer: (string) $whole,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findWholeBooks(float $difficulty, Randomizer $rng): MathProblem
    {
        $p = $this->pick($rng, [12, 15, 18, 24]);
        $whole = $p * 5 * $this->rnd($rng, 2, 8);
        $part  = $whole * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Прочитано \\($p\\%\\) — это \\($part\\) страниц. Всего в книге:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{$part}{$p} \\cdot 100",
                (string) $whole,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $whole . " \\text{ стр.}");

        return new MathProblem(
            question: "Ученик прочитал \\($p\\%\\) книги, это \\($part\\) страниц. "
                . "Сколько страниц в книге?",
            solution: $solution,
            answer: (string) $whole,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
