<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Combinatorics;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Комбинаторика.
 *
 * difficulty:
 *   0.0–0.35  — правило произведения: сколько вариантов из n₁·n₂·…
 *   0.35–0.60 — перестановки: P(n) = n!
 *   0.60–0.85 — размещения: A(n,k) = n! / (n−k)!
 *   0.85–1.00 — сочетания: C(n,k) = n! / (k!(n−k)!)
 *
 * Все ответы — целые числа. Для n до 10 включительно, чтобы
 * факториалы оставались в пределах int.
 */
final class CombinatoricsGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.combinatorics.rules.combinatorics';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.35 => $this->ruleOfProduct($difficulty),
            $difficulty < 0.60 => $this->permutations($difficulty),
            $difficulty < 0.85 => $this->arrangements($difficulty),
            default            => $this->combinations($difficulty),
        };
    }

    // ---------------------------------------------------------- правила

    private function ruleOfProduct(float $difficulty): MathProblem
    {
        $n1 = $this->rnd(2, 6);
        $n2 = $this->rnd(2, 6);
        $n3 = random_int(0, 1) === 1 ? $this->rnd(2, 5) : null;

        $total = $n1 * $n2 * ($n3 ?? 1);

        $steps = [
            "\\text{первый выбор: } & $n1 \\text{ вариантов}",
            "\\text{второй выбор: } & $n2 \\text{ вариантов}",
        ];
        if ($n3 !== null) {
            $steps[] = "\\text{третий выбор: } & $n3 \\text{ варианта}";
        }
        $steps[] = "\\text{всего: } & " . implode(' \\cdot ', array_filter([$n1, $n2, $n3])) . " = $total";

        $solution = "**Решение:**\n\n"
            . "По правилу произведения варианты перемножаются:\n\n"
            . KaTeX::display(KaTeX::alignedRaw($steps))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $total);

        $items = $n3 === null
            ? "Выбираем одно из $n1 блюд и один из $n2 напитков. Сколько есть вариантов обеда?"
            : "Выбираем одно из $n1 блюд, один из $n2 напитков и один из $n3 десертов. Сколько есть вариантов обеда?";

        return new MathProblem(
            question: $items,
            solution: $solution,
            answer: (string) $total,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    // ---------------------------------------------------------- перестановки

    private function permutations(float $difficulty): MathProblem
    {
        $n = $this->rnd(3, 7);
        $p = self::fact($n);

        $chain = implode(' \\cdot ', range($n, 1, -1));

        $solution = "**Решение:**\n\n"
            . "Число перестановок из $n элементов:\n\n"
            . KaTeX::display('P_n = n!')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "P_{$n} = {$n}!",
                "P_{$n} = $chain",
                "P_{$n} = $p",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $p);

        return new MathProblem(
            question: "Сколькими способами можно расставить $n различных книг на полке?",
            solution: $solution,
            answer: (string) $p,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    // ---------------------------------------------------------- размещения

    private function arrangements(float $difficulty): MathProblem
    {
        $n = $this->rnd(5, 9);
        $k = $this->rnd(2, $n - 1);

        $a = self::arrangementsCount($n, $k);

        $chain = implode(' \\cdot ', range($n, $n - $k + 1, -1));

        $solution = "**Решение:**\n\n"
            . "Размещения — выбор $k элементов из $n с учётом порядка:\n\n"
            . KaTeX::display('A_n^k = \dfrac{n!}{(n - k)!}')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "A_{$n}^{$k} = \\dfrac{{$n}!}{(" . ($n - $k) . ")!}",
                "A_{$n}^{$k} = $chain",
                "A_{$n}^{$k} = $a",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $a);

        return new MathProblem(
            question: "Сколькими способами можно выбрать $k из $n различных предметов, если порядок важен?",
            solution: $solution,
            answer: (string) $a,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    // ---------------------------------------------------------- сочетания

    private function combinations(float $difficulty): MathProblem
    {
        $n = $this->rnd(6, 10);
        $k = $this->rnd(2, $n - 2);

        $c = self::combinationsCount($n, $k);
        $k2 = $n - $k;

        $solution = "**Решение:**\n\n"
            . "Сочетания — выбор $k элементов из $n без учёта порядка:\n\n"
            . KaTeX::display('C_n^k = \dfrac{n!}{k!\,(n - k)!}')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "C_{$n}^{$k} &= \\dfrac{{$n}!}{{$k}! \\cdot ({$n} - $k)!} \\\\",
                "&= \\dfrac{{$n}!}{{$k}! \\cdot {$k2}!} \\\\",
                "&= $c",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $c);

        return new MathProblem(
            question: "Сколькими способами можно выбрать $k из $n различных предметов, если порядок не важен?",
            solution: $solution,
            answer: (string) $c,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    // ---------------------------------------------------------- помощники

    private static function fact(int $n): int
    {
        $r = 1;
        for ($i = 2; $i <= $n; $i++) {
            $r *= $i;
        }
        return $r;
    }

    private static function arrangementsCount(int $n, int $k): int
    {
        $r = 1;
        for ($i = $n - $k + 1; $i <= $n; $i++) {
            $r *= $i;
        }
        return $r;
    }

    private static function combinationsCount(int $n, int $k): int
    {
        if ($k > $n - $k) {
            $k = $n - $k;
        }
        $r = 1;
        for ($i = 0; $i < $k; $i++) {
            $r = intdiv($r * ($n - $i), $i + 1);
        }
        return $r;
    }
}
