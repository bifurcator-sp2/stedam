<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\WordProblems;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Задачи на работу: A = p · t (работа = производительность · время).
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — найти объём работы
 *   [0.2–0.4) — найти время по работе и производительности
 *   [0.4–0.6) — найти производительность
 *   [0.6–0.8) — совместная работа (складываем производительности)
 *   [0.8–1.0] — работа вместе, время
 */
final class WorkGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->findWork($difficulty, $rng),
                fn () => $this->findDetali($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->findTime($difficulty, $rng),
                fn () => $this->findDays($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->findRate($difficulty, $rng),
                fn () => $this->findRateMachine($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->togetherVolume($difficulty, $rng),
                fn () => $this->togetherPipes($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->togetherTime($difficulty, $rng),
                fn () => $this->togetherFinish($difficulty, $rng),
            ])(),
        };
    }

    private function findWork(float $difficulty, Randomizer $rng): MathProblem
    {
        $rate = $this->rnd($rng, 5, 30);
        $t = $this->rnd($rng, 2, 8);
        $work = $rate * $t;

        $solution = "**Решение:**\n\n"
            . "Объём работы = производительность · время:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "A = p \\cdot t",
                "$work = $rate \\cdot $t",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $work);

        return new MathProblem(
            question: "Рабочий делает \\($rate\\) деталей в час. Сколько деталей он сделает за \\($t\\) часов?",
            solution: $solution,
            answer: (string) $work,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findDetali(float $difficulty, Randomizer $rng): MathProblem
    {
        $rate = $this->rnd($rng, 10, 50);
        $t = $this->rnd($rng, 2, 8);
        $work = $rate * $t;

        $solution = "**Решение:**\n\n"
            . "Всего изготовлено:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$rate \\cdot $t",
                (string) $work,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$work \\text{ дет.}");

        return new MathProblem(
            question: "Станок выпускает \\($rate\\) деталей в час. Сколько деталей он выпустит за \\($t\\) часов?",
            solution: $solution,
            answer: (string) $work,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findTime(float $difficulty, Randomizer $rng): MathProblem
    {
        $rate = $this->rnd($rng, 5, 25);
        $t = $this->rnd($rng, 2, 8);
        $work = $rate * $t;

        $solution = "**Решение:**\n\n"
            . "Время = работа : производительность:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "t = \\dfrac{A}{p}",
                "t = \\dfrac{$work}{$rate} = $t",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$t \\text{ ч}");

        return new MathProblem(
            question: "Нужно изготовить \\($work\\) деталей, а рабочий делает \\($rate\\) деталей в час. "
                . "За сколько часов он справится?",
            solution: $solution,
            answer: (string) $t,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findDays(float $difficulty, Randomizer $rng): MathProblem
    {
        $rate = $this->rnd($rng, 3, 15);
        $t = $this->rnd($rng, 2, 10);
        $work = $rate * $t;

        $solution = "**Решение:**\n\n"
            . "Количество дней:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "t = \\dfrac{$work}{$rate}",
                (string) $t,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$t \\text{ дн.}");

        return new MathProblem(
            question: "Бригада должна уложить \\($work\\) метров дороги, укладывая по \\($rate\\) м в день. "
                . "За сколько дней она справится?",
            solution: $solution,
            answer: (string) $t,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findRate(float $difficulty, Randomizer $rng): MathProblem
    {
        $rate = $this->rnd($rng, 5, 30);
        $t = $this->rnd($rng, 2, 8);
        $work = $rate * $t;

        $solution = "**Решение:**\n\n"
            . "Производительность = работа : время:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "p = \\dfrac{A}{t}",
                "p = \\dfrac{$work}{$t} = $rate",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$rate \\text{ дет./ч}");

        return new MathProblem(
            question: "За \\($t\\) часов рабочий сделал \\($work\\) деталей. "
                . "Какова его производительность (деталей в час)?",
            solution: $solution,
            answer: (string) $rate,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findRateMachine(float $difficulty, Randomizer $rng): MathProblem
    {
        $t = $this->rnd($rng, 2, 8);
        $rate = $this->rnd($rng, 5, 30);
        $work = $rate * $t;

        $solution = "**Решение:**\n\n"
            . "Производительность:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "p = \\dfrac{$work}{$t}",
                (string) $rate,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$rate \\text{ дет./ч}");

        return new MathProblem(
            question: "Станок за \\($t\\) часов выпустил \\($work\\) деталей. "
                . "Сколько деталей он делает в час?",
            solution: $solution,
            answer: (string) $rate,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function togetherVolume(float $difficulty, Randomizer $rng): MathProblem
    {
        $r1 = $this->rnd($rng, 3, 12);
        $r2 = $this->rnd($rng, 3, 12);
        $t = $this->rnd($rng, 2, 6);
        $work = ($r1 + $r2) * $t;

        $solution = "**Решение:**\n\n"
            . "При совместной работе производительности складываются:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "A = (p_1 + p_2) \\cdot t",
                "A = ($r1 + $r2) \\cdot $t",
                "A = $work",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $work);

        return new MathProblem(
            question: "Первый рабочий делает \\($r1\\) деталей в час, второй — \\($r2\\). "
                . "Сколько деталей они сделают вместе за \\($t\\) часов?",
            solution: $solution,
            answer: (string) $work,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function togetherPipes(float $difficulty, Randomizer $rng): MathProblem
    {
        $r1 = $this->rnd($rng, 3, 15);
        $r2 = $this->rnd($rng, 3, 15);
        $t = $this->rnd($rng, 2, 6);
        $work = ($r1 + $r2) * $t;

        $solution = "**Решение:**\n\n"
            . "Две трубы вместе наливают \\((p_1 + p_2)\\) литров в час:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "A = ($r1 + $r2) \\cdot $t",
                "A = $work",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$work \\text{ л}");

        return new MathProblem(
            question: "Одна труба наливает \\($r1\\) л в час, другая — \\($r2\\) л в час. "
                . "Сколько литров они нальют вместе за \\($t\\) часов?",
            solution: $solution,
            answer: (string) $work,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function togetherTime(float $difficulty, Randomizer $rng): MathProblem
    {
        $r1 = $this->rnd($rng, 2, 10);
        $r2 = $this->rnd($rng, 2, 10);
        $total = $r1 + $r2;
        $t = $this->rnd($rng, 2, 6);
        $work = $total * $t;

        $solution = "**Решение:**\n\n"
            . "Совместная производительность: \\($r1 + $r2 = $total\\). "
            . "Время на объём \\($work\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "t = \\dfrac{A}{p_1 + p_2}",
                "t = \\dfrac{$work}{$total} = $t",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$t \\text{ ч}");

        return new MathProblem(
            question: "Первый рабочий делает \\($r1\\) деталей в час, второй — \\($r2\\). "
                . "За сколько часов вместе они сделают \\($work\\) деталей?",
            solution: $solution,
            answer: (string) $t,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function togetherFinish(float $difficulty, Randomizer $rng): MathProblem
    {
        $r1 = $this->rnd($rng, 2, 10);
        $r2 = $this->rnd($rng, 2, 10);
        $total = $r1 + $r2;
        $t = $this->rnd($rng, 2, 6);
        $work = $total * $t;

        $solution = "**Решение:**\n\n"
            . "Суммарная производительность \\($total\\). Время:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "t = \\dfrac{$work}{p_1 + p_2}",
                "t = \\dfrac{$work}{$total} = $t",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$t \\text{ ч}");

        return new MathProblem(
            question: "Две бригады вместе за час выполняют \\($total\\) единиц работы. "
                . "За сколько часов они вместе выполнят объём \\($work\\)?",
            solution: $solution,
            answer: (string) $t,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
