<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Numbers;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

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
    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick([
                fn () => $this->assembleThree($difficulty),
                fn () => $this->composeNumber($difficulty),
            ])(),
            $difficulty < 0.4 => $this->pick([
                fn () => $this->digitInThree($difficulty),
                fn () => $this->whichDigit($difficulty),
            ])(),
            $difficulty < 0.6 => $this->pick([
                fn () => $this->decomposeFour($difficulty),
                fn () => $this->sumOfPlaces($difficulty),
            ])(),
            $difficulty < 0.8 => $this->pick([
                fn () => $this->digitInFour($difficulty),
                fn () => $this->hundredsDigit($difficulty),
            ])(),
            default => $this->pick([
                fn () => $this->decomposeFive($difficulty),
                fn () => $this->assembleFive($difficulty),
            ])(),
        };
    }

    private function assembleThree(float $difficulty): MathProblem
    {
        $h = $this->rnd(1, 9);
        $d = $this->rnd(0, 9);
        $u = $this->rnd(0, 9);
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

    private function composeNumber(float $difficulty): MathProblem
    {
        $h = $this->rnd(1, 9);
        $d = $this->rnd(0, 9);
        $u = $this->rnd(0, 9);
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

    private function digitInThree(float $difficulty): MathProblem
    {
        $h = $this->rnd(1, 9);
        $d = $this->rnd(1, 9);
        $u = $this->rnd(1, 9);
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

    private function whichDigit(float $difficulty): MathProblem
    {
        $h = $this->rnd(1, 9);
        $d = $this->rnd(0, 9);
        $u = $this->rnd(0, 9);
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

    private function decomposeFour(float $difficulty): MathProblem
    {
        $th = $this->rnd(1, 9);
        $h = $this->rnd(0, 9);
        $d = $this->rnd(0, 9);
        $u = $this->rnd(0, 9);
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

    private function sumOfPlaces(float $difficulty): MathProblem
    {
        $th = $this->rnd(1, 9);
        $h = $this->rnd(0, 9);
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

    private function digitInFour(float $difficulty): MathProblem
    {
        $th = $this->rnd(1, 9);
        $h = $this->rnd(1, 9);
        $d = $this->rnd(1, 9);
        $u = $this->rnd(1, 9);
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

    private function hundredsDigit(float $difficulty): MathProblem
    {
        $th = $this->rnd(1, 9);
        $h = $this->rnd(0, 9);
        $d = $this->rnd(0, 9);
        $u = $this->rnd(0, 9);
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

    private function decomposeFive(float $difficulty): MathProblem
    {
        $tn = $this->rnd(1, 9);
        $th = $this->rnd(0, 9);
        $h = $this->rnd(0, 9);
        $d = $this->rnd(0, 9);
        $u = $this->rnd(0, 9);
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

    private function assembleFive(float $difficulty): MathProblem
    {
        $tn = $this->rnd(1, 9);
        $th = $this->rnd(0, 9);
        $h = $this->rnd(0, 9);
        $d = $this->rnd(0, 9);
        $u = $this->rnd(0, 9);
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
