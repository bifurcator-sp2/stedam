<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Generators\Math\Arithmetic\Numbers;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Infrastructure\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Infrastructure\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Округление до десятков / сотен / тысяч.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — до десятков, чёткий случай
 *   [0.2–0.4) — до десятков, с переносом
 *   [0.4–0.6) — до сотен
 *   [0.6–0.8) — до сотен, большие числа
 *   [0.8–1.0] — до тысяч
 */
final class RoundingGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->roundTensSimple($difficulty, $rng),
                fn () => $this->roundTensWord($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->roundTensCarry($difficulty, $rng),
                fn () => $this->roundTensPrice($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->roundHundreds($difficulty, $rng),
                fn () => $this->roundHundredsWord($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->roundHundredsBig($difficulty, $rng),
                fn () => $this->roundHundredsPeople($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->roundThousands($difficulty, $rng),
                fn () => $this->roundThousandsDist($difficulty, $rng),
            ])(),
        };
    }

    private function roundTensSimple(float $difficulty, Randomizer $rng): MathProblem
    {
        $unit = 10;
        $n = $this->rnd($rng, 10, 49);
        $rounded = (int) (round($n / $unit) * $unit);

        $solution = "**Решение:**\n\n"
            . "Смотрим на цифру единиц:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{число: } & $n",
                "\\text{цифра единиц: } & " . ($n % 10),
                "\\text{округление до десятков: } & $rounded",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $rounded);

        return new MathProblem(
            question: "Округлите \\($n\\) до десятков.",
            solution: $solution,
            answer: (string) $rounded,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function roundTensWord(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 10, 49);
        $rounded = (int) (round($n / 10) * 10);

        $solution = "**Решение:**\n\n"
            . "До десятков:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{было: } & $n",
                "\\text{стало: } & $rounded",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $rounded);

        return new MathProblem(
            question: "В классе \\($n\\) учеников. Округлите это число до десятков.",
            solution: $solution,
            answer: (string) $rounded,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function roundTensCarry(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 50, 99);
        $rounded = (int) (round($n / 10) * 10);
        $onesDigit = $n % 10;

        $solution = "**Решение:**\n\n"
            . "Цифра единиц \\($onesDigit \\geq 5\\), поэтому округляем вверх:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{число: } & $n",
                "\\text{округление до десятков: } & $rounded",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $rounded);

        return new MathProblem(
            question: "Округлите число \\($n\\) до десятков.",
            solution: $solution,
            answer: (string) $rounded,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function roundTensPrice(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 50, 99);
        $rounded = (int) (round($n / 10) * 10);

        $solution = "**Решение:**\n\n"
            . "Округлим цену до десятков:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{цена: } & $n \\text{ ₽}",
                "\\text{округлённо: } & $rounded \\text{ ₽}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $rounded . " \\text{ ₽}");

        return new MathProblem(
            question: "Товар стоит \\($n\\) ₽. Округлите стоимость до десятков рублей.",
            solution: $solution,
            answer: (string) $rounded,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function roundHundreds(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 100, 999);
        $rounded = (int) (round($n / 100) * 100);
        $tensDigit = intdiv($n, 10) % 10;

        $solution = "**Решение:**\n\n"
            . "Смотрим на цифру десятков \\($tensDigit\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{число: } & $n",
                "\\text{цифра десятков: } & $tensDigit",
                "\\text{округление до сотен: } & $rounded",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $rounded);

        return new MathProblem(
            question: "Округлите \\($n\\) до сотен.",
            solution: $solution,
            answer: (string) $rounded,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function roundHundredsWord(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 100, 999);
        $rounded = (int) (round($n / 100) * 100);

        $solution = "**Решение:**\n\n"
            . "Округлим число до сотен:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{население: } & $n \\text{ чел.}",
                "\\text{округлённо: } & $rounded \\text{ чел.}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $rounded);

        return new MathProblem(
            question: "В городе живёт \\($n\\) человек. Округлите это число до сотен.",
            solution: $solution,
            answer: (string) $rounded,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function roundHundredsBig(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 1000, 9999);
        $rounded = (int) (round($n / 100) * 100);
        $tensDigit = intdiv($n, 10) % 10;

        $solution = "**Решение:**\n\n"
            . "Нам важна только цифра десятков \\($tensDigit\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{число: } & $n",
                "\\text{округление до сотен: } & $rounded",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $rounded);

        return new MathProblem(
            question: "Округлите число \\($n\\) до сотен.",
            solution: $solution,
            answer: (string) $rounded,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function roundHundredsPeople(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 1000, 9999);
        $rounded = (int) (round($n / 100) * 100);

        $solution = "**Решение:**\n\n"
            . "Округлим до сотен:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{зрители: } & $n",
                "\\text{округлённо: } & $rounded",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $rounded);

        return new MathProblem(
            question: "Стадион посетило \\($n\\) зрителей. Округлите это число до сотен.",
            solution: $solution,
            answer: (string) $rounded,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function roundThousands(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 1000, 99999);
        $rounded = (int) (round($n / 1000) * 1000);
        $hundredsDigit = intdiv($n, 100) % 10;

        $solution = "**Решение:**\n\n"
            . "Смотрим на цифру сотен \\($hundredsDigit\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{число: } & $n",
                "\\text{цифра сотен: } & $hundredsDigit",
                "\\text{округление до тысяч: } & $rounded",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $rounded);

        return new MathProblem(
            question: "Округлите \\($n\\) до тысяч.",
            solution: $solution,
            answer: (string) $rounded,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function roundThousandsDist(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 1000, 99999);
        $rounded = (int) (round($n / 1000) * 1000);

        $solution = "**Решение:**\n\n"
            . "Округлим расстояние до тысяч километров:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{расстояние: } & $n \\text{ км}",
                "\\text{округлённо: } & $rounded \\text{ км}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $rounded . " \\text{ км}");

        return new MathProblem(
            question: "Расстояние между городами \\($n\\) км. Округлите его до тысяч километров.",
            solution: $solution,
            answer: (string) $rounded,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
