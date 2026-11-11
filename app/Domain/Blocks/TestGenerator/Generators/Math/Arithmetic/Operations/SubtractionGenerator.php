<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\Arithmetic\Operations;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Вычитание: всегда неотрицательный результат.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — в пределах 10
 *   [0.2–0.4) — в пределах 100
 *   [0.4–0.6) — с переходом через разряд
 *   [0.6–0.8) — трёхзначные числа
 *   [0.8–1.0] — многозначные и вычитание суммы
 */
final class SubtractionGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->minusShort($difficulty, $rng),
                fn () => $this->leftApples($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->minusTens($difficulty, $rng),
                fn () => $this->leftPassengers($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->borrowStep($difficulty, $rng),
                fn () => $this->discountDiff($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->minusHundreds($difficulty, $rng),
                fn () => $this->daysLeft($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->minusBig($difficulty, $rng),
                fn () => $this->minusSum($difficulty, $rng),
            ])(),
        };
    }

    private function minusShort(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 10);
        $b = $this->rnd($rng, 1, $a - 1);
        $diff = $a - $b;

        $solution = "**Решение:**\n\n"
            . "Вычтем из большего числа меньшее:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a - $b",
                (string) $diff,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $diff);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a - $b"),
            solution: $solution,
            answer: (string) $diff,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function leftApples(float $difficulty, Randomizer $rng): MathProblem
    {
        $total = $this->rnd($rng, 3, 10);
        $taken = $this->rnd($rng, 1, $total - 1);
        $left = $total - $taken;

        $solution = "**Решение:**\n\n"
            . "Осталось яблок:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$total - $taken",
                (string) $left,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $left . " \\text{ ябл.}");

        return new MathProblem(
            question: "В вазе лежало \\($total\\) яблок. Съели \\($taken\\). "
                . "Сколько яблок осталось?",
            solution: $solution,
            answer: (string) $left,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function minusTens(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 20, 99);
        $b = $this->rnd($rng, 10, $a - 1);
        $diff = $a - $b;

        $solution = "**Решение:**\n\n"
            . "Вычтем в столбик:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a - $b",
                (string) $diff,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $diff);

        return new MathProblem(
            question: "Найдите разность чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $diff,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function leftPassengers(float $difficulty, Randomizer $rng): MathProblem
    {
        $total = $this->rnd($rng, 30, 99);
        $exited = $this->rnd($rng, 10, $total - 1);
        $left = $total - $exited;

        $solution = "**Решение:**\n\n"
            . "Осталось пассажиров:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$total - $exited",
                (string) $left,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $left . " \\text{ чел.}");

        return new MathProblem(
            question: "В автобусе ехало \\($total\\) человек. На остановке вышло \\($exited\\). "
                . "Сколько пассажиров осталось?",
            solution: $solution,
            answer: (string) $left,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function borrowStep(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 100, 500);
        $b = $this->rnd($rng, 10, 99);
        $diff = $a - $b;

        $solution = "**Решение:**\n\n"
            . "Вычтем в столбик, занимая десяток из старшего разряда:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a - $b",
                (string) $diff,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $diff);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a - $b"),
            solution: $solution,
            answer: (string) $diff,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function discountDiff(float $difficulty, Randomizer $rng): MathProblem
    {
        $old = $this->rnd($rng, 100, 999);
        $new = $this->rnd($rng, 50, $old - 1);
        $diff = $old - $new;

        $solution = "**Решение:**\n\n"
            . "Товар подешевел на:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$old - $new",
                (string) $diff,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $diff . " \\text{ ₽}");

        return new MathProblem(
            question: "Товар стоил \\($old\\) ₽, после скидки стал стоить \\($new\\) ₽. "
                . "На сколько рублей подешевел товар?",
            solution: $solution,
            answer: (string) $diff,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function minusHundreds(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 1000, 9999);
        $b = $this->rnd($rng, 100, 999);
        $diff = $a - $b;

        $solution = "**Решение:**\n\n"
            . "Вычтем в столбик:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a - $b",
                (string) $diff,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $diff);

        return new MathProblem(
            question: "Чему равна разность " . KaTeX::inline("$a - $b") . "?",
            solution: $solution,
            answer: (string) $diff,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function daysLeft(float $difficulty, Randomizer $rng): MathProblem
    {
        $total = $this->rnd($rng, 200, 999);
        $done = $this->rnd($rng, 50, $total - 1);
        $left = $total - $done;

        $solution = "**Решение:**\n\n"
            . "Осталось сделать:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$total - $done",
                (string) $left,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $left . " \\text{ дет.}");

        return new MathProblem(
            question: "Завод должен выпустить \\($total\\) деталей, уже выпущено \\($done\\). "
                . "Сколько деталей осталось выпустить?",
            solution: $solution,
            answer: (string) $left,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function minusBig(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 10000, 99999);
        $b = $this->rnd($rng, 1000, 9999);
        $diff = $a - $b;

        $solution = "**Решение:**\n\n"
            . "Вычтем в столбик:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a - $b",
                (string) $diff,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $diff);

        return new MathProblem(
            question: "Вычислите разность: " . KaTeX::inline("$a - $b"),
            solution: $solution,
            answer: (string) $diff,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function minusSum(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 5000, 9999);
        $b = $this->rnd($rng, 1000, 4999);
        $c = $this->rnd($rng, 10, 999);
        $diff = $a - $b - $c;
        if ($diff < 0) {
            [$b, $c] = [$c + $b - $a + 1, 0];
            $diff = $a - $b;
        }

        $solution = "**Решение:**\n\n"
            . "Сначала вычтем второе число, затем третье:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a - $b - $c",
                (string) ($a - $b) . " - $c",
                (string) $diff,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $diff);

        return new MathProblem(
            question: "Вычислите: " . KaTeX::inline("$a - $b - $c"),
            solution: $solution,
            answer: (string) $diff,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
