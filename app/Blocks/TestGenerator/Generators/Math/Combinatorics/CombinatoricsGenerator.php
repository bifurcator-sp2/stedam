<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Combinatorics;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Комбинаторика: правило умножения, перестановки, сочетания, размещения.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — правило умножения (одежда/меню)
 *   [0.2–0.4) — перестановки Pₙ = n!
 *   [0.4–0.6) — перестановки (рассадка/слова)
 *   [0.6–0.8) — сочетания C(n,k)
 *   [0.8–1.0] — размещения A(n,k)
 */
final class CombinatoricsGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->productRule($difficulty, $rng),
                fn () => $this->menu($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->permutations($difficulty, $rng),
                fn () => $this->permutationsWord($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->seating($difficulty, $rng),
                fn () => $this->words($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->combinations($difficulty, $rng),
                fn () => $this->teamCombinations($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->arrangements($difficulty, $rng),
                fn () => $this->podium($difficulty, $rng),
            ])(),
        };
    }

    private function productRule(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 6);
        $b = $this->rnd($rng, 2, 6);
        $c = $this->rnd($rng, 2, 6);
        $total = $a * $b * $c;

        $solution = "**Решение:**\n\n"
            . "По правилу умножения перемножаем число вариантов на каждом шаге:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\cdot $b \\cdot $c = $total",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        return new MathProblem(
            question: "Есть \\($a\\) вида рубашек, \\($b\\) вида брюк и \\($c\\) вида обуви. "
                . "Сколько разных комплектов можно составить?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function menu(float $difficulty, Randomizer $rng): MathProblem
    {
        $a = $this->rnd($rng, 2, 6);
        $b = $this->rnd($rng, 2, 6);
        $total = $a * $b;

        $solution = "**Решение:**\n\n"
            . "Каждое первое блюдо можно сочетать с любым вторым:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$a \\cdot $b = $total",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        return new MathProblem(
            question: "В меню \\($a\\) первых блюда и \\($b\\) вторых. "
                . "Сколько вариантов обеда из двух блюд можно выбрать?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function permutations(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 3, 6);
        $total = self::factorial($n);

        $solution = "**Решение:**\n\n"
            . "Число перестановок из \\($n\\) элементов: \\(P_n = n!\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P_{$n} = $n!",
                "= " . self::factorialExpr($n) . " = $total",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        return new MathProblem(
            question: "Сколькими способами можно расставить \\($n\\) разных книг в ряд на полке?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function permutationsWord(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 3, 6);
        $total = self::factorial($n);

        $solution = "**Решение:**\n\n"
            . "Число перестановок \\(P_n = n!\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$n! = " . self::factorialExpr($n) . " = $total",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        return new MathProblem(
            question: "\\($n\\) человек выстраиваются в шеренгу. "
                . "Сколькими способами это можно сделать?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function seating(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 4, 6);
        $total = self::factorial($n);

        $solution = "**Решение:**\n\n"
            . "Перестановки за столом по \\(P_n = n!\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$n! = " . self::factorialExpr($n) . " = $total",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        return new MathProblem(
            question: "\\($n\\) гостей рассаживаются на \\($n\\) стульев в ряд. "
                . "Сколько есть способов рассадки?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function words(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 3, 5);
        $total = self::factorial($n);

        $solution = "**Решение:**\n\n"
            . "Число перестановок \\($n\\) различных букв:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P_{$n} = $n! = " . self::factorialExpr($n) . " = $total",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        return new MathProblem(
            question: "Сколько различных \\($n\\)-буквенных «слов» можно составить "
                . "из \\($n\\) разных букв (каждая используется один раз)?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function combinations(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 4, 7);
        $k = $this->rnd($rng, 2, $n - 2);
        $total = self::combination($n, $k);

        $solution = "**Решение:**\n\n"
            . "Число сочетаний: \\(C_n^k = \\dfrac{n!}{k!(n-k)!}\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "C_{$n}^{$k} = \\dfrac{" . $n . "!}{" . $k . "!\\,(" . ($n - $k) . ")!}",
                "= $total",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        return new MathProblem(
            question: "Из \\($n\\) предметов выбирают \\($k\\) (порядок не важен). "
                . "Сколькими способами это можно сделать?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function teamCombinations(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 5, 8);
        $k = $this->rnd($rng, 2, $n - 2);
        $total = self::combination($n, $k);

        $solution = "**Решение:**\n\n"
            . "Выбираем \\($k\\) из \\($n\\) без учёта порядка, число сочетаний:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "C_{$n}^{$k} = \\dfrac{" . $n . "!}{" . $k . "!\\,(" . ($n - $k) . ")!}",
                "= $total",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        return new MathProblem(
            question: "Из \\($n\\) учеников нужно выбрать команду из \\($k\\) человек. "
                . "Сколькими способами это можно сделать?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function arrangements(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 4, 7);
        $k = $this->rnd($rng, 2, $n - 1);
        $total = self::arrangement($n, $k);

        $solution = "**Решение:**\n\n"
            . "Число размещений: \\(A_n^k = \\dfrac{n!}{(n-k)!}\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "A_{$n}^{$k} = \\dfrac{" . $n . "!}{(" . ($n - $k) . ")!}",
                "= $total",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        return new MathProblem(
            question: "Из \\($n\\) предметов выбирают \\($k\\) и расставляют по порядку. "
                . "Сколько существует способов?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function podium(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 4, 8);
        $k = $this->rnd($rng, 2, 3);
        $total = self::arrangement($n, $k);

        $solution = "**Решение:**\n\n"
            . "Порядок важен, поэтому используем размещения \\(A_n^k\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "A_{$n}^{$k} = \\dfrac{" . $n . "!}{(" . ($n - $k) . ")!}",
                "= $total",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        return new MathProblem(
            question: "В забеге участвуют \\($n\\) спортсменов. Сколькими способами могут "
                . "распределиться первые \\($k\\) мест (призовой подиум)?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private static function factorial(int $n): int
    {
        $result = 1;
        for ($i = 2; $i <= $n; $i++) {
            $result *= $i;
        }
        return $result;
    }

    private static function factorialExpr(int $n): string
    {
        $parts = [];
        for ($i = $n; $i >= 2; $i--) {
            $parts[] = (string) $i;
        }
        return implode(' \\cdot ', $parts);
    }

    private static function combination(int $n, int $k): int
    {
        return intdiv(self::factorial($n), self::factorial($k) * self::factorial($n - $k));
    }

    private static function arrangement(int $n, int $k): int
    {
        return intdiv(self::factorial($n), self::factorial($n - $k));
    }
}
