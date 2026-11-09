<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Combinatorics;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Графы: степень вершины, рёбра, рукопожатия, деревья, формула Эйлера.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — рукопожатия (полный граф) n(n−1)/2
 *   [0.2–0.4) — рёбра дерева (n − 1)
 *   [0.4–0.6) — сумма рёбер из степеней вершин
 *   [0.6–0.8) — полный граф, обратная задача (найти число вершин)
 *   [0.8–1.0] — формула Эйлера V − E + F = 2
 */
final class GraphsGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->handshakes($difficulty, $rng),
                fn () => $this->greetings($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->treeEdges($difficulty, $rng),
                fn () => $this->railwayTree($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->sumDegrees($difficulty, $rng),
                fn () => $this->handshakeDegrees($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->completeGraphInverse($difficulty, $rng),
                fn () => $this->completeGraphInverseWord($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->euler($difficulty, $rng),
                fn () => $this->eulerEdges($difficulty, $rng),
            ])(),
        };
    }

    private function handshakes(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 3, 8);
        $edges = intdiv($n * ($n - 1), 2);

        $solution = "**Решение:**\n\n"
            . "Каждый из \\($n\\) человек пожимает руку каждому из остальных, "
            . "каждое рукопожатие считаем дважды:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $n . " \\cdot (" . $n . " - 1)}{2}",
                "= \\dfrac{" . ($n * ($n - 1)) . "}{2} = $edges",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $edges);

        return new MathProblem(
            question: "\\($n\\) человек при встрече обменялись рукопожатиями (каждый с каждым). "
                . "Сколько было рукопожатий?",
            solution: $solution,
            answer: (string) $edges,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function greetings(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 3, 8);
        $edges = intdiv($n * ($n - 1), 2);

        $solution = "**Решение:**\n\n"
            . "Число пар из \\($n\\) — это \\(\\dfrac{n(n-1)}{2}\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $n . " \\cdot (" . $n . " - 1)}{2} = $edges",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $edges);

        return new MathProblem(
            question: "В турнире каждый участник (их всего \\($n\\)) сыграл с каждым по одной партии. "
                . "Сколько всего партий было сыграно?",
            solution: $solution,
            answer: (string) $edges,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function treeEdges(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 3, 12);
        $edges = $n - 1;

        $solution = "**Решение:**\n\n"
            . "У дерева с \\($n\\) вершинами ровно \\(n - 1\\) ребро:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$n - 1 = $edges",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $edges);

        return new MathProblem(
            question: "Связный граф без циклов (дерево) имеет \\($n\\) вершин. "
                . "Сколько у него рёбер?",
            solution: $solution,
            answer: (string) $edges,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function railwayTree(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 3, 12);
        $edges = $n - 1;

        $solution = "**Решение:**\n\n"
            . "Дерево из \\($n\\) вершин содержит \\(n - 1\\) рёбер:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$n - 1 = $edges",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $edges);

        return new MathProblem(
            question: "\\($n\\) городов нужно соединить дорогами так, чтобы из любого города "
                . "можно было попасть в любой другой, но без лишних дорог (дерево). "
                . "Сколько дорог потребуется?",
            solution: $solution,
            answer: (string) $edges,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function sumDegrees(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 3, 6);
        $degrees = [];
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $d = $this->rnd($rng, 1, 4);
            $degrees[] = $d;
            $sum += $d;
        }
        if ($sum % 2 !== 0) {
            $degrees[0] += 1;
            $sum += 1;
        }
        $edges = intdiv($sum, 2);
        $degreeStr = implode(' + ', $degrees);

        $solution = "**Решение:**\n\n"
            . "Сумма степеней всех вершин равна удвоенному числу рёбер:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\sum \\deg = $degreeStr = $sum",
                "E = \\dfrac{$sum}{2} = $edges",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $edges);

        return new MathProblem(
            question: "В графе степени вершин равны: \\(" . implode('\\), \\(', $degrees) . "\\). "
                . "Сколько рёбер в этом графе?",
            solution: $solution,
            answer: (string) $edges,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function handshakeDegrees(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 3, 5);
        $degrees = [];
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $d = $this->rnd($rng, 1, 4);
            $degrees[] = $d;
            $sum += $d;
        }
        if ($sum % 2 !== 0) {
            $degrees[0] += 1;
            $sum += 1;
        }
        $edges = intdiv($sum, 2);
        $degreeStr = implode(' + ', $degrees);

        $solution = "**Решение:**\n\n"
            . "По лемме о рукопожатиях сумма степеней = \\(2E\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$degreeStr = $sum",
                "E = \\dfrac{$sum}{2} = $edges",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $edges);

        return new MathProblem(
            question: "У \\($n\\) человек количества знакомых равны \\("
                . implode('\\), \\(', $degrees) . "\\). "
                . "Сколько всего пар знакомых (рёбер) в этом графе знакомств?",
            solution: $solution,
            answer: (string) $edges,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function completeGraphInverse(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 3, 8);
        $edges = intdiv($n * ($n - 1), 2);
        $pairs = $n * ($n - 1);

        $solution = "**Решение:**\n\n"
            . "В полном графе \\(E = \\dfrac{n(n-1)}{2} = $edges\\). "
            . "Отсюда \\(n(n-1) = $pairs\\), значит \\(n = $n\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{n(n - 1)}{2} = $edges",
                "n(n - 1) = $pairs",
                "n = $n",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $n);

        return new MathProblem(
            question: "В полном графе \\($edges\\) рёбер. Сколько в нём вершин?",
            solution: $solution,
            answer: (string) $n,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function completeGraphInverseWord(float $difficulty, Randomizer $rng): MathProblem
    {
        $n = $this->rnd($rng, 3, 8);
        $edges = intdiv($n * ($n - 1), 2);

        $solution = "**Решение:**\n\n"
            . "Если игроков \\(n\\), партий \\(\\dfrac{n(n-1)}{2}\\). При \\($edges\\) партиях:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{n(n - 1)}{2} = $edges",
                "n = $n",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $n);

        return new MathProblem(
            question: "В турнире «каждый с каждым» сыграли \\($edges\\) партий. "
                . "Сколько было участников?",
            solution: $solution,
            answer: (string) $n,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function euler(float $difficulty, Randomizer $rng): MathProblem
    {
        $v = $this->rnd($rng, 4, 12);
        $e = $this->rnd($rng, $v - 1, $v + 6);
        $f = 2 - $v + $e;
        if ($f < 1) {
            $f = 1;
            $e = $v + $f - 2;
        }

        $solution = "**Решение:**\n\n"
            . "Формула Эйлера для планарного графа: \\(V - E + F = 2\\). "
            . "Найдём число граней:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "F = 2 - V + E",
                "F = 2 - $v + $e = $f",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $f);

        return new MathProblem(
            question: "Связный планарный граф имеет \\($v\\) вершин и \\($e\\) рёбер. "
                . "Сколько у него граней (включая внешнюю)?",
            solution: $solution,
            answer: (string) $f,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function eulerEdges(float $difficulty, Randomizer $rng): MathProblem
    {
        $v = $this->rnd($rng, 4, 12);
        $f = $this->rnd($rng, 2, 10);
        $e = $v + $f - 2;

        $solution = "**Решение:**\n\n"
            . "Из формулы Эйлера \\(V - E + F = 2\\) выразим рёбра:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "E = V + F - 2",
                "E = $v + $f - 2 = $e",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $e);

        return new MathProblem(
            question: "Связный планарный граф имеет \\($v\\) вершин и \\($f\\) граней. "
                . "Сколько у него рёбер?",
            solution: $solution,
            answer: (string) $e,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
