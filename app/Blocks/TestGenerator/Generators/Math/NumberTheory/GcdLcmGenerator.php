<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\NumberTheory;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * НОД и НОК (алгоритм Евклида).
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — НОД небольших чисел
 *   [0.2–0.4) — НОК небольших чисел
 *   [0.4–0.6) — НОД средних чисел
 *   [0.6–0.8) — НОК средних чисел
 *   [0.8–1.0] — НОД/НОК больших чисел
 */
final class GcdLcmGenerator extends GeneratorBase
{
    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick([
                fn () => $this->gcdSmall($difficulty),
                fn () => $this->gcdTiles($difficulty),
            ])(),
            $difficulty < 0.4 => $this->pick([
                fn () => $this->lcmSmall($difficulty),
                fn () => $this->lcmBuses($difficulty),
            ])(),
            $difficulty < 0.6 => $this->pick([
                fn () => $this->gcdMid($difficulty),
                fn () => $this->gcdRibbon($difficulty),
            ])(),
            $difficulty < 0.8 => $this->pick([
                fn () => $this->lcmMid($difficulty),
                fn () => $this->lcmGear($difficulty),
            ])(),
            default => $this->pick([
                fn () => $this->gcdBig($difficulty),
                fn () => $this->lcmBig($difficulty),
            ])(),
        };
    }

    private function gcdSmall(float $difficulty): MathProblem
    {
        $a = $this->rnd(6, 40);
        $b = $this->rnd(6, 40);
        if ($a === $b) {
            $b = $a + 1;
        }
        $gcd = self::computeGcd($a, $b);

        $solution = "**Решение:**\n\n"
            . "Найдём \\(\\gcd($a, $b)\\) алгоритмом Евклида:\n\n"
            . KaTeX::display(KaTeX::alignedRaw(self::euclidSteps($a, $b)))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $gcd);

        return new MathProblem(
            question: "Найдите наибольший общий делитель чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $gcd,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function gcdTiles(float $difficulty): MathProblem
    {
        $a = $this->rnd(6, 40);
        $b = $this->rnd(6, 40);
        if ($a === $b) {
            $b = $a + 2;
        }
        $gcd = self::computeGcd($a, $b);

        $solution = "**Решение:**\n\n"
            . "Сторона наибольшей плитки — это \\(\\gcd($a, $b)\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw(self::euclidSteps($a, $b)))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $gcd . " \\text{ см}");

        return new MathProblem(
            question: "Прямоугольный пол \\($a\\) на \\($b\\) см нужно замостить квадратными плитками "
                . "наибольшего размера. Какова сторона плитки?",
            solution: $solution,
            answer: (string) $gcd,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function lcmSmall(float $difficulty): MathProblem
    {
        $a = $this->rnd(4, 20);
        $b = $this->rnd(4, 20);
        if ($a === $b) {
            $b = $a + 1;
        }
        $gcd = self::computeGcd($a, $b);
        $lcm = intdiv($a * $b, $gcd);

        $solution = "**Решение:**\n\n"
            . "Сначала найдём \\(\\gcd($a, $b)\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw(self::euclidSteps($a, $b)))
            . "\n\nЗатем \\(\\operatorname{lcm}(a, b) = \\dfrac{a \\cdot b}{\\gcd(a, b)}\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\operatorname{lcm}($a, $b)",
                "\\dfrac{{$a} \\cdot {$b}}{$gcd}",
                (string) $lcm,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $lcm);

        return new MathProblem(
            question: "Найдите наименьшее общее кратное чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $lcm,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function lcmBuses(float $difficulty): MathProblem
    {
        $a = $this->rnd(4, 20);
        $b = $this->rnd(4, 20);
        if ($a === $b) {
            $b = $a + 1;
        }
        $gcd = self::computeGcd($a, $b);
        $lcm = intdiv($a * $b, $gcd);

        $solution = "**Решение:**\n\n"
            . "Время встречи — это \\(\\operatorname{lcm}($a, $b)\\).\n\n"
            . KaTeX::display(KaTeX::alignedRaw(self::euclidSteps($a, $b)))
            . "\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\operatorname{lcm} = \\dfrac{{$a} \\cdot {$b}}{$gcd} = $lcm",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$lcm \\text{ мин}");

        return new MathProblem(
            question: "Автобус №1 приходит каждые \\($a\\) минут, №2 — каждые \\($b\\) минут. "
                . "Они вместе отправились сейчас. Через сколько минут они снова отправятся вместе?",
            solution: $solution,
            answer: (string) $lcm,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function gcdMid(float $difficulty): MathProblem
    {
        $a = $this->rnd(30, 200);
        $b = $this->rnd(30, 200);
        if ($a === $b) {
            $b = $a + 1;
        }
        $gcd = self::computeGcd($a, $b);

        $solution = "**Решение:**\n\n"
            . "Применим алгоритм Евклида:\n\n"
            . KaTeX::display(KaTeX::alignedRaw(self::euclidSteps($a, $b)))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $gcd);

        return new MathProblem(
            question: "Найдите наибольший общий делитель чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $gcd,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function gcdRibbon(float $difficulty): MathProblem
    {
        $a = $this->rnd(30, 200);
        $b = $this->rnd(30, 200);
        if ($a === $b) {
            $b = $a + 5;
        }
        $gcd = self::computeGcd($a, $b);

        $solution = "**Решение:**\n\n"
            . "Длина наибольшего куска — \\(\\gcd($a, $b)\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw(self::euclidSteps($a, $b)))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $gcd . " \\text{ см}");

        return new MathProblem(
            question: "Две ленты длиной \\($a\\) см и \\($b\\) см нужно разрезать на равные куски "
                . "наибольшей длины без остатка. Какова длина куска?",
            solution: $solution,
            answer: (string) $gcd,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function lcmMid(float $difficulty): MathProblem
    {
        $a = $this->rnd(10, 40);
        $b = $this->rnd(10, 40);
        if ($a === $b) {
            $b = $a + 1;
        }
        $gcd = self::computeGcd($a, $b);
        $lcm = intdiv($a * $b, $gcd);

        $solution = "**Решение:**\n\n"
            . "Сначала \\(\\gcd($a, $b)\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw(self::euclidSteps($a, $b)))
            . "\n\nЗатем НОК:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\operatorname{lcm} = \\dfrac{{$a} \\cdot {$b}}{$gcd} = $lcm",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $lcm);

        return new MathProblem(
            question: "Найдите наименьшее общее кратное чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $lcm,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function lcmGear(float $difficulty): MathProblem
    {
        $a = $this->rnd(10, 40);
        $b = $this->rnd(10, 40);
        if ($a === $b) {
            $b = $a + 2;
        }
        $gcd = self::computeGcd($a, $b);
        $lcm = intdiv($a * $b, $gcd);

        $solution = "**Решение:**\n\n"
            . "Число оборотов до совпадения меток — это \\(\\operatorname{lcm}($a, $b)\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw(self::euclidSteps($a, $b)))
            . "\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\operatorname{lcm} = \\dfrac{{$a} \\cdot {$b}}{$gcd} = $lcm",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $lcm);

        return new MathProblem(
            question: "Две шестерни имеют \\($a\\) и \\($b\\) зубьев. Через сколько оборотов меньшей "
                . "шестерни метки на обеих снова совпадут (это НОК числа зубьев)?",
            solution: $solution,
            answer: (string) $lcm,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function gcdBig(float $difficulty): MathProblem
    {
        $a = $this->rnd(200, 900);
        $b = $this->rnd(200, 900);
        if ($a === $b) {
            $b = $a + 1;
        }
        $gcd = self::computeGcd($a, $b);

        $solution = "**Решение:**\n\n"
            . "Алгоритм Евклида:\n\n"
            . KaTeX::display(KaTeX::alignedRaw(self::euclidSteps($a, $b)))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $gcd);

        return new MathProblem(
            question: "Найдите наибольший общий делитель чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $gcd,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function lcmBig(float $difficulty): MathProblem
    {
        $a = $this->rnd(20, 90);
        $b = $this->rnd(20, 90);
        if ($a === $b) {
            $b = $a + 1;
        }
        $gcd = self::computeGcd($a, $b);
        $lcm = intdiv($a * $b, $gcd);

        $solution = "**Решение:**\n\n"
            . "Сначала \\(\\gcd($a, $b)\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw(self::euclidSteps($a, $b)))
            . "\n\nЗатем НОК:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\operatorname{lcm} = \\dfrac{{$a} \\cdot {$b}}{$gcd} = $lcm",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $lcm);

        return new MathProblem(
            question: "Найдите наименьшее общее кратное чисел \\($a\\) и \\($b\\).",
            solution: $solution,
            answer: (string) $lcm,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private static function computeGcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return $a;
    }

    /**
     * @return list<string> строки для KaTeX::alignedRaw()
     */
    private static function euclidSteps(int $a, int $b): array
    {
        $x = $a;
        $y = $b;
        $lines = [];
        while ($y !== 0) {
            $q = intdiv($x, $y);
            $r = $x % $y;
            $lines[] = "$x = $y \\cdot $q + $r";
            [$x, $y] = [$y, $r];
        }
        return $lines;
    }
}
