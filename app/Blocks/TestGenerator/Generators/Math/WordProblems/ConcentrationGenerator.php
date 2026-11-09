<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\WordProblems;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Задачи на концентрацию и проценты в растворах.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — найти массу вещества в растворе
 *   [0.2–0.4) — найти концентрацию (в процентах)
 *   [0.4–0.6) — найти массу раствора по массе вещества
 *   [0.6–0.8) — простые проценты (сахар в сиропе / кислота)
 *   [0.8–1.0] — концентрация после разбавления
 */
final class ConcentrationGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->findMass($difficulty, $rng),
                fn () => $this->findSalt($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->findPercent($difficulty, $rng),
                fn () => $this->findPercentJuice($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->findSolution($difficulty, $rng),
                fn () => $this->findSolutionSugar($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->sugarInSyrup($difficulty, $rng),
                fn () => $this->acidInSolution($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->dilution($difficulty, $rng),
                fn () => $this->addWater($difficulty, $rng),
            ])(),
        };
    }

    private function findMass(float $difficulty, Randomizer $rng): MathProblem
    {
        $mass = $this->rnd($rng, 2, 10) * 100;
        $p = $this->pick($rng, [5, 10, 20, 25, 40]);
        $substance = $mass * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Масса вещества = масса раствора · концентрация:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "m = $mass \\cdot \\dfrac{$p}{100}",
                $this->num($substance),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($substance) . " \\text{ г}");

        return new MathProblem(
            question: "Есть \\($mass\\) г раствора с концентрацией соли \\($p\\%\\). "
                . "Сколько граммов соли в растворе?",
            solution: $solution,
            answer: $this->num($substance),
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findSalt(float $difficulty, Randomizer $rng): MathProblem
    {
        $mass = $this->rnd($rng, 2, 10) * 100;
        $p = $this->pick($rng, [5, 10, 20, 25]);
        $substance = $mass * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Содержание соли:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$mass \\cdot \\dfrac{$p}{100}",
                $this->num($substance),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($substance) . " \\text{ г}");

        return new MathProblem(
            question: "\\($mass\\) г морской воды содержат \\($p\\%\\) соли. "
                . "Сколько соли в этой воде?",
            solution: $solution,
            answer: $this->num($substance),
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findPercent(float $difficulty, Randomizer $rng): MathProblem
    {
        $mass = $this->rnd($rng, 2, 10) * 100;
        $p = $this->pick($rng, [5, 10, 20, 25, 50]);
        $substance = $mass * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Концентрация = масса вещества : масса раствора, умноженная на 100\\%:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $this->num($substance) . "}{$mass} \\cdot 100\\%",
                $p . "\\%",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($p . "\\%");

        return new MathProblem(
            question: "В \\($mass\\) г раствора содержится " . $this->num($substance) . " г вещества. "
                . "Какова концентрация раствора (в процентах)?",
            solution: $solution,
            answer: $p . '%',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findPercentJuice(float $difficulty, Randomizer $rng): MathProblem
    {
        $mass = $this->rnd($rng, 2, 10) * 100;
        $p = $this->pick($rng, [10, 20, 25, 50]);
        $substance = $mass * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Доля сока в напитке:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $this->num($substance) . "}{$mass} \\cdot 100\\%",
                $p . "\\%",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($p . "\\%");

        return new MathProblem(
            question: "В \\($mass\\) г напитка содержится " . $this->num($substance) . " г сока. "
                . "Сколько процентов сока в напитке?",
            solution: $solution,
            answer: $p . '%',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findSolution(float $difficulty, Randomizer $rng): MathProblem
    {
        $p = $this->pick($rng, [10, 20, 25, 50]);
        $mass = $this->rnd($rng, 2, 10) * 100;
        $substance = $mass * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Если \\($p\\%\\) — это " . $this->num($substance) . " г, то масса раствора:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $this->num($substance) . "}{$p} \\cdot 100",
                (string) $mass,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$mass \\text{ г}");

        return new MathProblem(
            question: "Раствор содержит " . $this->num($substance) . " г вещества, что составляет \\($p\\%\\) "
                . "от всей массы. Какова масса раствора?",
            solution: $solution,
            answer: (string) $mass,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findSolutionSugar(float $difficulty, Randomizer $rng): MathProblem
    {
        $p = $this->pick($rng, [10, 20, 25]);
        $mass = $this->rnd($rng, 2, 10) * 100;
        $substance = $mass * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Масса сиропа:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $this->num($substance) . "}{$p} \\cdot 100",
                (string) $mass,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$mass \\text{ г}");

        return new MathProblem(
            question: "В сиропе " . $this->num($substance) . " г сахара — это \\($p\\%\\) от массы сиропа. "
                . "Какова масса сиропа?",
            solution: $solution,
            answer: (string) $mass,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function sugarInSyrup(float $difficulty, Randomizer $rng): MathProblem
    {
        $mass = $this->pick($rng, [200, 300, 400, 500]);
        $p = $this->pick($rng, [5, 10, 15, 20]);
        $substance = $mass * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Количество сахара в сиропе:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "m = $mass \\cdot \\dfrac{$p}{100}",
                $this->num($substance),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($substance) . " \\text{ г}");

        return new MathProblem(
            question: "Приготовили \\($mass\\) г сиропа с содержанием сахара \\($p\\%\\). "
                . "Сколько сахара в сиропе?",
            solution: $solution,
            answer: $this->num($substance),
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function acidInSolution(float $difficulty, Randomizer $rng): MathProblem
    {
        $mass = $this->pick($rng, [200, 400, 500, 1000]);
        $p = $this->pick($rng, [5, 10, 12, 15]);
        $substance = $mass * $p / 100;

        $solution = "**Решение:**\n\n"
            . "Масса кислоты:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$mass \\cdot \\dfrac{$p}{100}",
                $this->num($substance),
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($substance) . " \\text{ г}");

        return new MathProblem(
            question: "Раствор массой \\($mass\\) г содержит \\($p\\%\\) кислоты. "
                . "Сколько граммов кислоты?",
            solution: $solution,
            answer: $this->num($substance),
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function dilution(float $difficulty, Randomizer $rng): MathProblem
    {
        $mass = $this->pick($rng, [200, 300, 400]);
        $p = $this->pick($rng, [10, 20, 25]);
        $substance = $mass * $p / 100;
        $newMass = $mass + $this->pick($rng, [200, 300]);
        $newP = $substance / $newMass * 100;

        $solution = "**Решение:**\n\n"
            . "Вещество остаётся " . $this->num($substance) . " г, а раствор стал \\($newMass\\) г. "
            . "Новая концентрация:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $this->num($substance) . "}{$newMass} \\cdot 100\\%",
                $this->num($newP) . "\\%",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($newP) . "\\%");

        return new MathProblem(
            question: "К \\($mass\\) г раствора с концентрацией \\($p\\%\\) долили воды до "
                . "\\($newMass\\) г. Какой стала концентрация вещества?",
            solution: $solution,
            answer: $this->num($newP) . '%',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function addWater(float $difficulty, Randomizer $rng): MathProblem
    {
        $mass = $this->pick($rng, [200, 400]);
        $p = $this->pick($rng, [10, 20, 25]);
        $substance = $mass * $p / 100;
        $added = $this->pick($rng, [100, 200, 300]);
        $newMass = $mass + $added;
        $newP = $substance / $newMass * 100;

        $solution = "**Решение:**\n\n"
            . "Масса вещества не меняется: " . $this->num($substance) . " г. "
            . "Раствор стал \\($newMass\\) г. Концентрация:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\dfrac{" . $this->num($substance) . "}{$newMass} \\cdot 100\\%",
                $this->num($newP) . "\\%",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline($this->num($newP) . "\\%");

        return new MathProblem(
            question: "Имеется \\($mass\\) г раствора с содержанием вещества \\($p\\%\\). "
                . "Добавили \\($added\\) г воды. Найдите новую концентрацию.",
            solution: $solution,
            answer: $this->num($newP) . '%',
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
