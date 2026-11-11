<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\Arithmetic\Numbers;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Счёт: продолжить последовательность с заданным шагом.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — шаг 1
 *   [0.2–0.4) — шаг 2 или 5
 *   [0.4–0.6) — шаг 10
 *   [0.6–0.8) — шаг 3 или 4
 *   [0.8–1.0] — произвольный шаг, поиск пропущенного члена
 */
final class CountingGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->nextByOne($difficulty, $rng),
                fn () => $this->countUp($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->nextByTwoFive($difficulty, $rng),
                fn () => $this->stairs($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->nextByTen($difficulty, $rng),
                fn () => $this->tensRow($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->nextByThreeFour($difficulty, $rng),
                fn () => $this->elevator($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->missingTerm($difficulty, $rng),
                fn () => $this->customStep($difficulty, $rng),
            ])(),
        };
    }

    private function nextByOne(float $difficulty, Randomizer $rng): MathProblem
    {
        $start = $this->rnd($rng, 1, 40);
        $seq = range($start, $start + 4);
        $next = $start + 5;
        $shown = implode(',\\; ', $seq);

        $solution = "**Решение:**\n\n"
            . "Каждое число больше предыдущего на \\(1\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{последовательность: } $shown, \\ldots \\\\",
                "\\text{следующее: } $next",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $next);

        return new MathProblem(
            question: "Продолжите последовательность: \\($shown, \\ldots\\). Какое число идёт следом?",
            solution: $solution,
            answer: (string) $next,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function countUp(float $difficulty, Randomizer $rng): MathProblem
    {
        $start = $this->rnd($rng, 1, 30);
        $seq = range($start, $start + 4);
        $next = $start + 5;
        $shown = implode(',\\; ', $seq);

        $solution = "**Решение:**\n\n"
            . "Считаем по порядку, прибавляя \\(1\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{ряд: } $shown, \\ldots \\\\",
                "\\text{следом: } $next",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $next);

        return new MathProblem(
            question: "Назовите число, которое следует за \\(" . ($start + 4) . "\\) в ряду \\($shown, \\ldots\\).",
            solution: $solution,
            answer: (string) $next,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function nextByTwoFive(float $difficulty, Randomizer $rng): MathProblem
    {
        $step = $this->pick($rng, [2, 5]);
        $start = $this->rnd($rng, 1, 20);
        $seq = [];
        for ($i = 0; $i < 5; $i++) {
            $seq[] = $start + $i * $step;
        }
        $next = $start + 5 * $step;
        $shown = implode(',\\; ', $seq);

        $solution = "**Решение:**\n\n"
            . "Каждое число больше предыдущего на \\($step\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{последовательность: } $shown, \\ldots \\\\",
                "\\text{следующее: } $next",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $next);

        return new MathProblem(
            question: "Продолжите последовательность: \\($shown, \\ldots\\). Какое число следующее?",
            solution: $solution,
            answer: (string) $next,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function stairs(float $difficulty, Randomizer $rng): MathProblem
    {
        $step = $this->pick($rng, [2, 5]);
        $start = $this->rnd($rng, 1, 20);
        $seq = [];
        for ($i = 0; $i < 5; $i++) {
            $seq[] = $start + $i * $step;
        }
        $next = $start + 5 * $step;
        $shown = implode(',\\; ', $seq);

        $solution = "**Решение:**\n\n"
            . "Ступеньки растут на \\($step\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{ступеньки: } $shown, \\ldots \\\\",
                "\\text{следующая: } $next",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $next);

        return new MathProblem(
            question: "Мальчик считает ступеньки, перешагивая по \\($step\\): \\($shown, \\ldots\\). "
                . "Какая ступенька будет следующей по счёту?",
            solution: $solution,
            answer: (string) $next,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function nextByTen(float $difficulty, Randomizer $rng): MathProblem
    {
        $start = $this->rnd($rng, 1, 60);
        $seq = [];
        for ($i = 0; $i < 5; $i++) {
            $seq[] = $start + $i * 10;
        }
        $next = $start + 50;
        $shown = implode(',\\; ', $seq);

        $solution = "**Решение:**\n\n"
            . "Каждое число больше предыдущего на \\(10\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{последовательность: } $shown, \\ldots \\\\",
                "\\text{следующее: } $next",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $next);

        return new MathProblem(
            question: "Продолжите счёт десятками: \\($shown, \\ldots\\). Назовите следующее число.",
            solution: $solution,
            answer: (string) $next,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function tensRow(float $difficulty, Randomizer $rng): MathProblem
    {
        $start = $this->rnd($rng, 1, 60);
        $seq = [];
        for ($i = 0; $i < 5; $i++) {
            $seq[] = $start + $i * 10;
        }
        $next = $start + 50;
        $shown = implode(',\\; ', $seq);

        $solution = "**Решение:**\n\n"
            . "Шаг ряда равен \\(10\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{ряд: } $shown, \\ldots \\\\",
                "\\text{следующий член: } $next",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $next);

        return new MathProblem(
            question: "Дан ряд \\($shown, \\ldots\\). Найдите его следующий член.",
            solution: $solution,
            answer: (string) $next,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function nextByThreeFour(float $difficulty, Randomizer $rng): MathProblem
    {
        $step = $this->pick($rng, [3, 4]);
        $start = $this->rnd($rng, 1, 15);
        $seq = [];
        for ($i = 0; $i < 5; $i++) {
            $seq[] = $start + $i * $step;
        }
        $next = $start + 5 * $step;
        $shown = implode(',\\; ', $seq);

        $solution = "**Решение:**\n\n"
            . "Шаг последовательности равен \\($step\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{последовательность: } $shown, \\ldots \\\\",
                "\\text{следующее: } $next",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $next);

        return new MathProblem(
            question: "Продолжите последовательность: \\($shown, \\ldots\\). Какое число идёт следом?",
            solution: $solution,
            answer: (string) $next,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function elevator(float $difficulty, Randomizer $rng): MathProblem
    {
        $step = $this->pick($rng, [3, 4]);
        $start = $this->rnd($rng, 1, 15);
        $seq = [];
        for ($i = 0; $i < 5; $i++) {
            $seq[] = $start + $i * $step;
        }
        $next = $start + 5 * $step;
        $shown = implode(',\\; ', $seq);

        $solution = "**Решение:**\n\n"
            . "Лифт проезжает по \\($step\\) этажа:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{этажи: } $shown, \\ldots \\\\",
                "\\text{следующий: } $next",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $next);

        return new MathProblem(
            question: "Лифт останавливается на этажах \\($shown, \\ldots\\), пропуская одинаковое число этажей. "
                . "Какой этаж будет следующим?",
            solution: $solution,
            answer: (string) $next,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function missingTerm(float $difficulty, Randomizer $rng): MathProblem
    {
        $step = $this->rnd($rng, 3, 9);
        $start = $this->rnd($rng, 1, 20);
        $seq = [];
        for ($i = 0; $i < 5; $i++) {
            $seq[] = $start + $i * $step;
        }
        $hiddenIndex = $this->rnd($rng, 1, 3);
        $hidden = $seq[$hiddenIndex];
        $display = $seq;
        $display[$hiddenIndex] = '?';
        $shown = implode(',\\; ', $display);

        $solution = "**Решение:**\n\n"
            . "Найдём шаг по соседним известным членам — он равен \\($step\\):\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{ряд: } $shown \\\\",
                "\\text{пропущенный член: } $hidden",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $hidden);

        return new MathProblem(
            question: "В последовательности \\($shown\\) одно число пропущено. Найдите его.",
            solution: $solution,
            answer: (string) $hidden,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function customStep(float $difficulty, Randomizer $rng): MathProblem
    {
        $step = $this->rnd($rng, 6, 12);
        $start = $this->rnd($rng, 1, 20);
        $seq = [];
        for ($i = 0; $i < 5; $i++) {
            $seq[] = $start + $i * $step;
        }
        $next = $start + 5 * $step;
        $shown = implode(',\\; ', $seq);

        $solution = "**Решение:**\n\n"
            . "Определим шаг: возьмём разность соседних членов — \\($step\\).\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{ряд: } $shown, \\ldots \\\\",
                "\\text{следующий член: } $next",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $next);

        return new MathProblem(
            question: "Дан числовой ряд \\($shown, \\ldots\\). Найдите его следующий член.",
            solution: $solution,
            answer: (string) $next,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
