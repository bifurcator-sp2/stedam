<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\NumberTheory;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Признаки делимости. Ответ — «да» или «нет».
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — делимость на 2 и на 5
 *   [0.2–0.4) — делимость на 10 и на 3
 *   [0.4–0.6) — делимость на 9 и на 4
 *   [0.6–0.8) — делимость на 6 и на 3 (большие числа)
 *   [0.8–1.0] — делимость на 9 и на 4 (большие числа)
 */
final class DivisibilityGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->ruleByTwo($difficulty, $rng),
                fn () => $this->ruleByFive($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->ruleByTen($difficulty, $rng),
                fn () => $this->ruleByThree($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->ruleByNine($difficulty, $rng),
                fn () => $this->ruleByFour($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->ruleBySix($difficulty, $rng),
                fn () => $this->ruleByThreeBig($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->ruleByNineBig($difficulty, $rng),
                fn () => $this->ruleByFourBig($difficulty, $rng),
            ])(),
        };
    }

    private function verdict(bool $ok): string
    {
        return $ok ? 'да' : 'нет';
    }

    private function yesNo(bool $ok): string
    {
        return $ok ? '\\text{да}' : '\\text{нет}';
    }

    /**
     * @param list<string> $detailLines
     */
    private function makeProblem(
        int $number,
        int $divisor,
        string $ruleText,
        string $question,
        array $detailLines,
        float $difficulty,
    ): MathProblem {
        $ok = $number % $divisor === 0;
        $verdict = $this->verdict($ok);

        $detailLines[] = "\\text{вывод: } $verdict";

        $solution = "**Решение:**\n\n"
            . $ruleText . "\n\n"
            . KaTeX::display(KaTeX::alignedRaw($detailLines))
            . "\n\n**Ответ:** " . KaTeX::inline($verdict);

        return new MathProblem(
            question: $question,
            solution: $solution,
            answer: $verdict,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function ruleByTwo(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 10, 999);
        $last = $n % 10;
        return $this->makeProblem(
            $n, 2,
            "Число делится на \\(2\\), если его последняя цифра чётная.",
            "Делится ли число \\($n\\) на \\(2\\)?",
            [
                "\\text{последняя цифра: } $last",
                "\\text{чётная? } " . $this->yesNo($last % 2 === 0),
            ],
            $difficulty,
        );
    }

    private function ruleByFive(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 10, 999);
        $last = $n % 10;
        return $this->makeProblem(
            $n, 5,
            "Число делится на \\(5\\), если оканчивается на \\(0\\) или \\(5\\).",
            "Делится ли число \\($n\\) на \\(5\\)?",
            [
                "\\text{последняя цифра: } $last",
                "\\text{оканчивается на 0 или 5? } " . $this->yesNo($last === 0 || $last === 5),
            ],
            $difficulty,
        );
    }

    private function ruleByTen(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 10, 999);
        $last = $n % 10;
        return $this->makeProblem(
            $n, 10,
            "Число делится на \\(10\\), если оканчивается на \\(0\\).",
            "Делится ли число \\($n\\) на \\(10\\)?",
            [
                "\\text{последняя цифра: } $last",
                "\\text{оканчивается на 0? } " . $this->yesNo($last === 0),
            ],
            $difficulty,
        );
    }

    private function ruleByThree(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 10, 999);
        $sum = array_sum(array_map('intval', str_split((string) $n)));
        return $this->makeProblem(
            $n, 3,
            "Число делится на \\(3\\), если сумма его цифр делится на \\(3\\).",
            "Делится ли число \\($n\\) на \\(3\\)?",
            [
                "\\text{сумма цифр: } $sum",
                "\\text{сумма кратна 3? } " . $this->yesNo($sum % 3 === 0),
            ],
            $difficulty,
        );
    }

    private function ruleByNine(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 100, 999);
        $sum = array_sum(array_map('intval', str_split((string) $n)));
        return $this->makeProblem(
            $n, 9,
            "Число делится на \\(9\\), если сумма его цифр делится на \\(9\\).",
            "Делится ли число \\($n\\) на \\(9\\)?",
            [
                "\\text{сумма цифр: } $sum",
                "\\text{сумма кратна 9? } " . $this->yesNo($sum % 9 === 0),
            ],
            $difficulty,
        );
    }

    private function ruleByFour(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 100, 9999);
        $lastTwo = $n % 100;
        return $this->makeProblem(
            $n, 4,
            "Число делится на \\(4\\), если две последние цифры образуют число, кратное \\(4\\).",
            "Делится ли число \\($n\\) на \\(4\\)?",
            [
                "\\text{две последние цифры: } $lastTwo",
                "\\text{кратно 4? } " . $this->yesNo($lastTwo % 4 === 0),
            ],
            $difficulty,
        );
    }

    private function ruleBySix(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 100, 9999);
        $sum = array_sum(array_map('intval', str_split((string) $n)));
        $even = $n % 2 === 0;
        return $this->makeProblem(
            $n, 6,
            "Число делится на \\(6\\), если оно делится и на \\(2\\), и на \\(3\\).",
            "Делится ли число \\($n\\) на \\(6\\)?",
            [
                "\\text{сумма цифр: } $sum",
                "\\text{кратно 3? } " . $this->yesNo($sum % 3 === 0),
                "\\text{чётное? } " . $this->yesNo($even),
            ],
            $difficulty,
        );
    }

    private function ruleByThreeBig(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 1000, 99999);
        $sum = array_sum(array_map('intval', str_split((string) $n)));
        return $this->makeProblem(
            $n, 3,
            "Число делится на \\(3\\), если сумма его цифр делится на \\(3\\).",
            "Делится ли число \\($n\\) на \\(3\\)?",
            [
                "\\text{сумма цифр: } $sum",
                "\\text{сумма кратна 3? } " . $this->yesNo($sum % 3 === 0),
            ],
            $difficulty,
        );
    }

    private function ruleByNineBig(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 1000, 99999);
        $sum = array_sum(array_map('intval', str_split((string) $n)));
        return $this->makeProblem(
            $n, 9,
            "Число делится на \\(9\\), если сумма его цифр делится на \\(9\\).",
            "Делится ли число \\($n\\) на \\(9\\)?",
            [
                "\\text{сумма цифр: } $sum",
                "\\text{сумма кратна 9? } " . $this->yesNo($sum % 9 === 0),
            ],
            $difficulty,
        );
    }

    private function ruleByFourBig(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 10000, 99999);
        $lastTwo = $n % 100;
        return $this->makeProblem(
            $n, 4,
            "Число делится на \\(4\\), если две последние цифры образуют число, кратное \\(4\\).",
            "Делится ли число \\($n\\) на \\(4\\)?",
            [
                "\\text{две последние цифры: } $lastTwo",
                "\\text{кратно 4? } " . $this->yesNo($lastTwo % 4 === 0),
            ],
            $difficulty,
        );
    }
}
