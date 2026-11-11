<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\Arithmetic\Numbers;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Разряды: собрать число из разрядов, разложить, узнать цифру разряда.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — трёхзначное, собрать из разрядов
 *   [0.2–0.4) — трёхзначное, назвать число по цифре разряда
 *   [0.4–0.6) — четырёхзначное, разложение на разряды
 *   [0.6–0.8) — четырёхзначное, определить цифру разряда
 *   [0.8–1.0] — пятизначное, разложение
 */
final class PlaceValueGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->assembleThree($difficulty, $rng),
                fn () => $this->composeNumber($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->digitInThree($difficulty, $rng),
                fn () => $this->whichDigit($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->decomposeFour($difficulty, $rng),
                fn () => $this->sumOfPlaces($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->digitInFour($difficulty, $rng),
                fn () => $this->hundredsDigit($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->decomposeFive($difficulty, $rng),
                fn () => $this->assembleFive($difficulty, $rng),
            ])(),
        };
    }

    private function assembleThree(float $difficulty, Randomizer $rng): MathProblem
    {
        $h = $this->rnd($rng, 1, 9);
        $d = $this->rnd($rng, 0, 9);
        $u = $this->rnd($rng, 0, 9);
        $number = $h * 100 + $d * 10 + $u;

        $solution = "**Решение:**\n\n"
            . "Составим число из разрядов:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{сотни: } & $h \\cdot 100 = " . ($h * 100),
                "\\text{десятки: } & $d \\cdot 10 = " . ($d * 10),
                "\\text{единицы: } & $u",
                "\\text{число: } & " . ($h * 100) . " + " . ($d * 10) . " + $u = $number",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $number);

        return new MathProblem(
            question: "Составьте число, в котором \\($h\\) сотен, \\($d\\) десятков и \\($u\\) единиц.",
            solution: $solution,
            answer: (string) $number,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function composeNumber(float $difficulty, Randomizer $rng): MathProblem
    {
        $h = $this->rnd($rng, 1, 9);
        $d = $this->rnd($rng, 0, 9);
        $u = $this->rnd($rng, 0, 9);
        $number = $h * 100 + $d * 10 + $u;

        $solution = "**Решение:**\n\n"
            . "Складываем разряды:\n\n"
            . KaTeX::display(KaTeX::aligned([
                ($h * 100) . " + " . ($d * 10) . " + $u",
                (string) $number,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $number);

        return new MathProblem(
            question: "Запишите число, равное " . KaTeX::inline(($h * 100) . " + " . ($d * 10) . " + $u") . ".",
            solution: $solution,
            answer: (string) $number,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function digitInThree(float $difficulty, Randomizer $rng): MathProblem
    {
        $h = $this->rnd($rng, 1, 9);
        $d = $this->rnd($rng, 1, 9);
        $u = $this->rnd($rng, 1, 9);
        $number = $h * 100 + $d * 10 + $u;

        $solution = "**Решение:**\n\n"
            . "Разложим число по разрядам:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$number = " . ($h * 100) . " + " . ($d * 10) . " + $u",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $d . " \\text{ десятков}");

        return new MathProblem(
            question: "Сколько десятков в числе \\($number\\)?",
            solution: $solution,
            answer: (string) $d,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function whichDigit(float $difficulty, Randomizer $rng): MathProblem
    {
        $h = $this->rnd($rng, 1, 9);
        $d = $this->rnd($rng, 0, 9);
        $u = $this->rnd($rng, 0, 9);
        $number = $h * 100 + $d * 10 + $u;

        $solution = "**Решение:**\n\n"
            . "Цифра сотен стоит на третьем месте слева:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$number = " . ($h * 100) . " + " . ($d * 10) . " + $u",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $h);

        return new MathProblem(
            question: "Какая цифра стоит в разряде сотен числа \\($number\\)?",
            solution: $solution,
            answer: (string) $h,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function decomposeFour(float $difficulty, Randomizer $rng): MathProblem
    {
        $th = $this->rnd($rng, 1, 9);
        $h = $this->rnd($rng, 0, 9);
        $d = $this->rnd($rng, 0, 9);
        $u = $this->rnd($rng, 0, 9);
        $number = $th * 1000 + $h * 100 + $d * 10 + $u;

        $solution = "**Решение:**\n\n"
            . "Разложим на разрядные слагаемые:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$number",
                ($th * 1000) . " + " . ($h * 100) . " + " . ($d * 10) . " + $u",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline(
                ($th * 1000) . " + " . ($h * 100) . " + " . ($d * 10) . " + $u"
            );

        return new MathProblem(
            question: "Представьте число \\($number\\) в виде суммы разрядных слагаемых.",
            solution: $solution,
            answer: "{$th}000 + " . ($h * 100) . " + " . ($d * 10) . " + $u",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function sumOfPlaces(float $difficulty, Randomizer $rng): MathProblem
    {
        $th = $this->rnd($rng, 1, 9);
        $h = $this->rnd($rng, 0, 9);
        $number = $th * 1000 + $h * 100;

        $solution = "**Решение:**\n\n"
            . "Разрядные слагаемые:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$number",
                ($th * 1000) . " + " . ($h * 100),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline(($th * 1000) . " + " . ($h * 100));

        return new MathProblem(
            question: "Запишите число \\($number\\) суммой тысяч и сотен.",
            solution: $solution,
            answer: ($th * 1000) . " + " . ($h * 100),
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function digitInFour(float $difficulty, Randomizer $rng): MathProblem
    {
        $th = $this->rnd($rng, 1, 9);
        $h = $this->rnd($rng, 1, 9);
        $d = $this->rnd($rng, 1, 9);
        $u = $this->rnd($rng, 1, 9);
        $number = $th * 1000 + $h * 100 + $d * 10 + $u;

        $solution = "**Решение:**\n\n"
            . "Разберём число по разрядам:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$number = " . ($th * 1000) . " + " . ($h * 100) . " + " . ($d * 10) . " + $u",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $th . " \\text{ тысяч}");

        return new MathProblem(
            question: "Сколько тысяч в числе \\($number\\)?",
            solution: $solution,
            answer: (string) $th,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function hundredsDigit(float $difficulty, Randomizer $rng): MathProblem
    {
        $th = $this->rnd($rng, 1, 9);
        $h = $this->rnd($rng, 0, 9);
        $d = $this->rnd($rng, 0, 9);
        $u = $this->rnd($rng, 0, 9);
        $number = $th * 1000 + $h * 100 + $d * 10 + $u;

        $solution = "**Решение:**\n\n"
            . "Разложим по разрядам:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$number = " . ($th * 1000) . " + " . ($h * 100) . " + " . ($d * 10) . " + $u",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $h);

        return new MathProblem(
            question: "Какая цифра стоит в разряде сотен числа \\($number\\)?",
            solution: $solution,
            answer: (string) $h,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function decomposeFive(float $difficulty, Randomizer $rng): MathProblem
    {
        $tn = $this->rnd($rng, 1, 9);
        $th = $this->rnd($rng, 0, 9);
        $h = $this->rnd($rng, 0, 9);
        $d = $this->rnd($rng, 0, 9);
        $u = $this->rnd($rng, 0, 9);
        $number = $tn * 10000 + $th * 1000 + $h * 100 + $d * 10 + $u;

        $solution = "**Решение:**\n\n"
            . "Разложим на разрядные слагаемые:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$number",
                ($tn * 10000) . " + " . ($th * 1000) . " + " . ($h * 100) . " + " . ($d * 10) . " + $u",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline(
                ($tn * 10000) . " + " . ($th * 1000) . " + " . ($h * 100) . " + " . ($d * 10) . " + $u"
            );

        return new MathProblem(
            question: "Представьте число \\($number\\) суммой разрядных слагаемых.",
            solution: $solution,
            answer: ($tn * 10000) . " + " . ($th * 1000) . " + " . ($h * 100) . " + " . ($d * 10) . " + $u",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function assembleFive(float $difficulty, Randomizer $rng): MathProblem
    {
        $tn = $this->rnd($rng, 1, 9);
        $th = $this->rnd($rng, 0, 9);
        $h = $this->rnd($rng, 0, 9);
        $d = $this->rnd($rng, 0, 9);
        $u = $this->rnd($rng, 0, 9);
        $number = $tn * 10000 + $th * 1000 + $h * 100 + $d * 10 + $u;

        $solution = "**Решение:**\n\n"
            . "Складываем разряды:\n\n"
            . KaTeX::display(KaTeX::aligned([
                ($tn * 10000) . " + " . ($th * 1000) . " + " . ($h * 100) . " + " . ($d * 10) . " + $u",
                (string) $number,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $number);

        return new MathProblem(
            question: "Запишите число, в котором \\($tn\\) десятков тысяч, \\($th\\) тысяч, "
                . "\\($h\\) сотен, \\($d\\) десятков и \\($u\\) единиц.",
            solution: $solution,
            answer: (string) $number,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
