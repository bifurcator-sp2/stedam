<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Combinatorics;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Графы.
 *
 * difficulty:
 *   0.0–0.40  — степень заданной вершины
 *   0.40–0.70 — число рёбер по сумме степеней (лемма о рукопожатиях)
 *   0.70–1.00 — есть ли эйлеров путь (0 или 2 вершины нечётной степени)
 *
 * Граф задаётся списком рёбер; вершины нумеруются с 1.
 * В условии граф выводится как последовательность рёбер (u—v).
 */
final class GraphsGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.combinatorics.graphs.graphs';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.40 => $this->vertexDegree($difficulty),
            $difficulty < 0.70 => $this->edgeCount($difficulty),
            default            => $this->eulerPath($difficulty),
        };
    }

    // ---------------------------------------------------------- степень

    private function vertexDegree(float $difficulty): MathProblem
    {
        [$edges, $n] = $this->randomGraph(
            minVertices: 4,
            maxVertices: 6,
            minEdges: 3,
            maxEdges: 7,
        );

        $v = $this->rnd(1, $n);
        $degrees = self::degrees($edges, $n);
        $answer = $degrees[$v];

        $edgesStr = self::formatEdges($edges);
        $stepLines = [
            "\\text{рёбра: } & $edgesStr",
            "\\text{степень вершины } $v\\text{: } & $answer",
        ];

        $solution = "**Решение:**\n\n"
            . "Степень вершины — число рёбер, выходящих из неё.\n\n"
            . KaTeX::display(KaTeX::alignedRaw($stepLines))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $answer);

        return new MathProblem(
            question: "Дан граф с рёбрами: $edgesStr. Найдите степень вершины $v.",
            solution: $solution,
            answer: (string) $answer,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    // ---------------------------------------------------------- число рёбер

    private function edgeCount(float $difficulty): MathProblem
    {
        [$edges, $n] = $this->randomGraph(
            minVertices: 4,
            maxVertices: 7,
            minEdges: 4,
            maxEdges: 9,
        );

        $degrees = self::degrees($edges, $n);
        $sum = array_sum($degrees);
        $count = intdiv($sum, 2);

        $parts = [];
        for ($i = 1; $i <= $n; $i++) {
            $parts[] = "\\deg(v_{$i}) = {$degrees[$i]}";
        }

        $solution = "**Решение:**\n\n"
            . "Сумма степеней всех вершин равна удвоенному числу рёбер "
            . "(лемма о рукопожатиях).\n\n"
            . KaTeX::display(KaTeX::alignedRaw($parts))
            . "\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\sum \\deg = $sum",
                "|E| = \\dfrac{$sum}{2} = $count",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $count);

        return new MathProblem(
            question: "В графе степени вершин: "
                . implode(', ', array_map(fn ($i) => "\\deg(v_{$i}) = {$degrees[$i]}", range(1, $n)))
                . ". Сколько в графе рёбер?",
            solution: $solution,
            answer: (string) $count,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    // ---------------------------------------------------------- эйлеров путь

    private function eulerPath(float $difficulty): MathProblem
    {
        // Сгенерируем связный граф путём последовательного добавления рёбер.
        [$edges, $n] = $this->randomGraph(
            minVertices: 4,
            maxVertices: 6,
            minEdges: 4,
            maxEdges: 7,
        );

        $degrees = self::degrees($edges, $n);

        $odd = 0;
        $parts = [];
        for ($i = 1; $i <= $n; $i++) {
            $parts[] = "\\deg(v_{$i}) = {$degrees[$i]}";
            if ($degrees[$i] % 2 !== 0) {
                $odd++;
            }
        }

        $canTraverse = ($odd === 0 || $odd === 2);
        $verdict = $canTraverse ? 'да' : 'нет';

        $edgesStr = self::formatEdges($edges);

        $solution = "**Решение:**\n\n"
            . "Эйлеров путь существует тогда и только тогда, когда "
            . "число вершин нечётной степени равно 0 или 2.\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "\\text{рёбра: } & $edgesStr",
                ...$parts,
                "\\text{вершин нечётной степени: } & $odd",
            ]))
            . "\n\n"
            . ($canTraverse
                ? "Значит, эйлеров путь существует."
                : "Значит, эйлерова пути не существует.")
            . "\n\n**Ответ:** " . KaTeX::inline($verdict);

        return new MathProblem(
            question: "Дан граф с рёбрами: $edgesStr. Можно ли обойти все его рёбра ровно по одному разу (эйлеров путь)? Ответьте «да» или «нет».",
            solution: $solution,
            answer: $verdict,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    // ---------------------------------------------------------- помощники

    /**
     * Строит связный случайный граф.
     *
     * Гарантирует связность: сначала создаётся дерево-остов
     * (n-1 рёбер), затем добавляются случайные рёбра до желаемого
     * количества. Между вершинами не бывает кратных рёбер и петель.
     *
     * @return array{0: list<array{0:int,1:int}>, 1: int}
     */
    private function randomGraph(
        int $minVertices,
        int $maxVertices,
        int $minEdges,
        int $maxEdges,
    ): array {
        $n = $this->rnd($minVertices, $maxVertices);

        $edgeSet = [];
        // Остовное дерево.
        for ($v = 2; $v <= $n; $v++) {
            $u = $this->rnd(1, $v - 1);
            $edgeSet[self::edgeKey($u, $v)] = [$u, $v];
        }

        $target = min(
            $maxEdges,
            max($minEdges, count($edgeSet) + $this->rnd(0, 3)),
        );

        $attempts = 0;
        while (count($edgeSet) < $target && $attempts < 50) {
            $u = $this->rnd(1, $n);
            $v = $this->rnd(1, $n);
            $attempts++;
            if ($u === $v) {
                continue;
            }
            $edgeSet[self::edgeKey($u, $v)] = [min($u, $v), max($u, $v)];
        }

        $edges = array_values($edgeSet);
        shuffle($edges);

        return [$edges, $n];
    }

    private static function edgeKey(int $u, int $v): string
    {
        return min($u, $v) . '-' . max($u, $v);
    }

    /**
     * @param list<array{0:int,1:int}> $edges
     * @return array<int, int> степени, индексированные с 1
     */
    private static function degrees(array $edges, int $n): array
    {
        $deg = array_fill(1, $n, 0);
        foreach ($edges as [$u, $v]) {
            $deg[$u]++;
            $deg[$v]++;
        }
        return $deg;
    }

    /**
     * @param list<array{0:int,1:int}> $edges
     */
    private static function formatEdges(array $edges): string
    {
        return implode(',\\; ', array_map(
            static fn (array $e): string => "{$e[0]}{-}{$e[1]}",
            $edges,
        ));
    }
}
