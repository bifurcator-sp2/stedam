<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\NumberTheory;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Признаки делимости.
 *
 * difficulty:
 *   0.0–0.3  — делимость на 2, 5, 10 (последняя цифра)
 *   0.3–0.6  — делимость на 3, 9 (сумма цифр)
 *   0.6–0.85 — делимость на 4 (последние две цифры)
 *   0.85–1.0 — делимость на 11 (знакочередующаяся сумма)
 */
final class DivisibilityGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.number_theory.divisibility.divisibility';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.30 => $this->bySimple($difficulty),
            $difficulty < 0.60 => $this->byThreeOrNine($difficulty),
            $difficulty < 0.85 => $this->byFour($difficulty),
            default            => $this->byEleven($difficulty),
        };
    }

    private function bySimple(float $difficulty): MathProblem
    {
        $divisor = $this->pick([2, 5, 10]);
        $makeMultiple = random_int(0, 1) === 1;

        if ($makeMultiple) {
            $k = $this->rnd(10, 99);
            $n = $k * $divisor;
        } else {
            do {
                $n = $this->rnd(100, 999);
            } while ($n % $divisor === 0);
        }

        $lastDigit = $n % 10;
        $isMultiple = $n % $divisor === 0;
        $verdict = $isMultiple ? 'делится' : 'не делится';

        $solution = "**Решение:**\n\n"
            . "Проверим по признаку делимости на \\($divisor\\) — "
            . ($divisor === 10
                ? "смотрим последнюю цифру (должна быть 0)."
                : "смотрим последнюю цифру.")
            . "\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{число: } & $n \\\\",
                "\\text{последняя цифра: } & $lastDigit",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($verdict);

        return new MathProblem(
            question: "Делится ли число \\($n\\) на \\($divisor\\) без остатка? Ответьте «да» или «нет».",
            solution: $solution,
            answer: $isMultiple ? 'да' : 'нет',
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function byThreeOrNine(float $difficulty): MathProblem
    {
        $divisor = $this->pick([3, 9]);
        $makeMultiple = random_int(0, 1) === 1;

        if ($makeMultiple) {
            $k = $this->rnd(20, 200);
            $n = $k * $divisor;
        } else {
            do {
                $n = $this->rnd(100, 999);
            } while ($n % $divisor === 0);
        }

        $digits = str_split((string) $n);
        $sum = array_sum(array_map('intval', $digits));
        $digitsStr = implode(' + ', $digits);

        $isMultiple = $n % $divisor === 0;
        $verdict = $isMultiple ? 'делится' : 'не делится';

        $solution = "**Решение:**\n\n"
            . "Признак делимости на \\($divisor\\): сумма цифр должна делиться на \\($divisor\\).\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{сумма цифр: } & $digitsStr = $sum \\\\",
                "\\text{$sum} \\bmod $divisor = " . ($sum % $divisor),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($verdict);

        return new MathProblem(
            question: "Делится ли число \\($n\\) на \\($divisor\\) без остатка? Ответьте «да» или «нет».",
            solution: $solution,
            answer: $isMultiple ? 'да' : 'нет',
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function byFour(float $difficulty): MathProblem
    {
        $makeMultiple = random_int(0, 1) === 1;

        if ($makeMultiple) {
            $k = $this->rnd(30, 300);
            $n = $k * 4;
        } else {
            do {
                $n = $this->rnd(100, 9999);
            } while ($n % 4 === 0);
        }

        $lastTwo = $n % 100;
        $isMultiple = $n % 4 === 0;
        $verdict = $isMultiple ? 'делится' : 'не делится';

        $solution = "**Решение:**\n\n"
            . "Признак делимости на \\(4\\): две последние цифры образуют число, кратное \\(4\\).\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{число: } & $n \\\\",
                "\\text{две последние цифры: } & " . str_pad((string) $lastTwo, 2, '0', STR_PAD_LEFT) . " \\\\",
                "$lastTwo \\bmod 4 = " . ($lastTwo % 4),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($verdict);

        return new MathProblem(
            question: "Делится ли число \\($n\\) на \\(4\\) без остатка? Ответьте «да» или «нет».",
            solution: $solution,
            answer: $isMultiple ? 'да' : 'нет',
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function byEleven(float $difficulty): MathProblem
    {
        $makeMultiple = random_int(0, 1) === 1;

        if ($makeMultiple) {
            $k = $this->rnd(20, 200);
            $n = $k * 11;
        } else {
            do {
                $n = $this->rnd(1000, 99999);
            } while ($n % 11 === 0);
        }

        $digits = array_map('intval', str_split((string) $n));
        $altSum = 0;
        for ($i = 0; $i < count($digits); $i++) {
            $altSum += ($i % 2 === 0) ? $digits[$i] : -$digits[$i];
        }

        $isMultiple = $n % 11 === 0;
        $verdict = $isMultiple ? 'делится' : 'не делится';

        $solution = "**Решение:**\n\n"
            . "Признак делимости на \\(11\\): знакочередующаяся сумма цифр кратна \\(11\\).\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{число: } & $n \\\\",
                "\\text{знакочередующаяся сумма: } & $altSum \\\\",
                "$altSum \\bmod 11 = " . (($altSum % 11 + 11) % 11),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($verdict);

        return new MathProblem(
            question: "Делится ли число \\($n\\) на \\(11\\) без остатка? Ответьте «да» или «нет».",
            solution: $solution,
            answer: $isMultiple ? 'да' : 'нет',
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
