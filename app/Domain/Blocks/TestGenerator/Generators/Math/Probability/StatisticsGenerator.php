<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\Probability;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Статистика.
 *
 * difficulty:
 *   0.0–0.35  — среднее арифметическое
 *   0.35–0.65 — медиана / мода / размах (один показатель)
 *   0.65–1.00 — все четыре показателя сразу
 *
 * Ряд — небольшие целые числа, чтобы медиана была однозначной.
 */
final class StatisticsGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        $series = $this->randomSeries($rng);

        return match (true) {
            $difficulty < 0.35 => $this->mean($difficulty, $series),
            $difficulty < 0.65 => $this->oneOfMedianModeRange($difficulty, $series, $rng),
            default            => $this->fullSummary($difficulty, $series),
        };
    }

    private function mean(float $difficulty, array $series): MathProblem
    {
        $sum = array_sum($series);
        $count = count($series);
        $mean = $sum / $count;
        $answer = $this->num($mean, 2);

        $sumStr = implode(' + ', $series);

        $solution = "**Решение:**\n\n"
            . "Среднее арифметическое — сумма элементов, делённая на их количество:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\bar{x} &= \\dfrac{$sumStr}{$count} \\\\",
                "  &= \\dfrac{" . $sum . "}{" . $count . "} \\\\",
                "  &= $answer",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($answer);

        return new MathProblem(
            question: "Дан ряд чисел: " . implode(', ', $series) . ". Найдите среднее арифметическое.",
            solution: $solution,
            answer: $answer,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function oneOfMedianModeRange(float $difficulty, array $series, Randomizer $rng): MathProblem
    {
        return match ($this->pick($rng, ['median', 'mode', 'range'])) {
            'median' => $this->median($difficulty, $series),
            'mode'   => $this->mode($difficulty, $series),
            'range'  => $this->range($difficulty, $series),
        };
    }

    private function median(float $difficulty, array $series): MathProblem
    {
        $sorted = $series;
        sort($sorted);
        $n = count($sorted);

        if ($n % 2 === 1) {
            $mid = $sorted[intdiv($n, 2)];
            $answer = (string) $mid;
            $steps = [
                "\\text{отсортированный ряд: } & " . implode(', ', $sorted) . " \\\\",
                "\\text{медиана (центральный элемент): } & $mid",
            ];
        } else {
            $a = $sorted[intdiv($n, 2) - 1];
            $b = $sorted[intdiv($n, 2)];
            $mid = ($a + $b) / 2;
            $answer = $this->num($mid, 2);
            $steps = [
                "\\text{отсортированный ряд: } & " . implode(', ', $sorted) . " \\\\",
                "\\text{два центральных элемента: } & $a, \\; $b \\\\",
                "\\text{медиана: } & \\dfrac{{$a} + {$b}}{2} = $answer",
            ];
        }

        $solution = "**Решение:**\n\n"
            . "Медиана — центральное значение упорядоченного ряда.\n\n"
            . KaTeX::display(KaTeX::alignedRaw($steps))
            . "\n\n**Ответ:** " . KaTeX::inline($answer);

        return new MathProblem(
            question: "Дан ряд чисел: " . implode(', ', $series) . ". Найдите медиану.",
            solution: $solution,
            answer: $answer,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function mode(float $difficulty, array $series): MathProblem
    {
        $counts = array_count_values($series);
        $maxFreq = max($counts);
        $modes = array_keys(array_filter($counts, static fn ($c) => $c === $maxFreq));
        sort($modes);

        if (count($modes) > 1) {
            $series[] = $modes[0];
            $counts = array_count_values($series);
            $maxFreq = max($counts);
            $modes = array_keys(array_filter($counts, static fn ($c) => $c === $maxFreq));
            sort($modes);
        }

        $answer = (string) $modes[0];

        $freqLines = [];
        ksort($counts);
        foreach ($counts as $value => $freq) {
            $freqLines[] = "\\text{число } & $value \\text{ — } $freq \\text{ раз(-)}";
        }

        $solution = "**Решение:**\n\n"
            . "Мода — значение, встречающееся чаще других.\n\n"
            . KaTeX::display(KaTeX::alignedRaw($freqLines))
            . "\n\n**Ответ:** " . KaTeX::inline($answer);

        return new MathProblem(
            question: "Дан ряд чисел: " . implode(', ', $series) . ". Найдите моду.",
            solution: $solution,
            answer: $answer,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function range(float $difficulty, array $series): MathProblem
    {
        $min = min($series);
        $max = max($series);
        $range = $max - $min;

        $solution = "**Решение:**\n\n"
            . "Размах — разность между наибольшим и наименьшим значениями.\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\min = $min, \\quad \\max = $max \\\\",
                "R = \\max - \\min = $max - $min = $range",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $range);

        return new MathProblem(
            question: "Дан ряд чисел: " . implode(', ', $series) . ". Найдите размах.",
            solution: $solution,
            answer: (string) $range,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function fullSummary(float $difficulty, array $series): MathProblem
    {
        $sorted = $series;
        sort($sorted);
        $n = count($sorted);

        $sum = array_sum($series);
        $mean = $this->num($sum / $n, 2);

        if ($n % 2 === 1) {
            $median = $sorted[intdiv($n, 2)];
        } else {
            $median = ($sorted[intdiv($n, 2) - 1] + $sorted[intdiv($n, 2)]) / 2;
        }
        $medianStr = $this->num((float) $median, 2);

        $counts = array_count_values($series);
        $maxFreq = max($counts);
        $modes = array_keys(array_filter($counts, static fn ($c) => $c === $maxFreq));
        sort($modes);
        if (count($modes) > 1) {
            $series[] = $modes[0];
            $counts = array_count_values($series);
            $maxFreq = max($counts);
            $modes = array_keys(array_filter($counts, static fn ($c) => $c === $maxFreq));
            sort($modes);
        }
        $mode = $modes[0];

        $min = min($series);
        $max = max($series);
        $range = $max - $min;

        $answer = "среднее $mean, медиана $medianStr, мода $mode, размах $range";

        $sumStr = implode(' + ', $series);

        $solution = "**Решение:**\n\n"
            . "Найдём все четыре характеристики ряда.\n\n"
            . "**Среднее арифметическое:**\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\bar{x} = \\dfrac{$sumStr}{$n} = $mean",
            ]))
            . "\n\n**Медиана** (после сортировки: " . implode(', ', $sorted) . "): \\($medianStr\\)\n\n"
            . "**Мода:** число \\($mode\\) встречается чаще других.\n\n"
            . "**Размах:** \\(\\max - \\min = $max - $min = $range\\)\n\n"
            . "**Ответ:** " . KaTeX::inline(
                "\\bar{x} = $mean,\\; Me = $medianStr,\\; Mo = $mode,\\; R = $range"
            );

        return new MathProblem(
            question: "Дан ряд чисел: " . implode(', ', $series)
            . ". Найдите среднее, медиану, моду и размах.",
            solution: $solution,
            answer: $answer,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    /**
     * @return list<int> ряд из 6–10 целых от 1 до 20
     */
    private function randomSeries(Randomizer $rng): array
    {
        $n = $this->rnd($rng, 6, 10);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->rnd($rng, 1, 20);
        }
        return $out;
    }
}
