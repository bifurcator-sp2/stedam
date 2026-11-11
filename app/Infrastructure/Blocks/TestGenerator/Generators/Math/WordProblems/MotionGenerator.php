<?php

declare(strict_types=1);

namespace App\Infrastructure\Blocks\TestGenerator\Generators\Math\WordProblems;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Infrastructure\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Infrastructure\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Задачи на движение: s = v · t.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — найти путь по скорости и времени
 *   [0.2–0.4) — найти время по пути и скорости
 *   [0.4–0.6) — найти скорость по пути и времени
 *   [0.6–0.8) — путь за несколько участков / время до встречи
 *   [0.8–1.0) — разность путей / скорость удаления
 */
final class MotionGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->findDistance($difficulty, $rng),
                fn () => $this->findDistanceCar($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->findTime($difficulty, $rng),
                fn () => $this->findTimeTrain($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->findSpeed($difficulty, $rng),
                fn () => $this->findSpeedSwimmer($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->combinedPath($difficulty, $rng),
                fn () => $this->meetingTime($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->relativeSpeed($difficulty, $rng),
                fn () => $this->overtake($difficulty, $rng),
            ])(),
        };
    }

    private function findDistance(float $difficulty, Randomizer $rng): MathProblem
    {
        $v = $this->rnd($rng, 3, 15) * 10;
        $t = $this->rnd($rng, 2, 6);
        $s = $v * $t;

        $solution = "**Решение:**\n\n"
            . "Путь равен произведению скорости на время:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "s = v \\cdot t",
                "$s = $v \\cdot $t = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$s \\text{ км}");

        return new MathProblem(
            question: "Велосипедист едет со скоростью \\($v\\) км/ч в течение \\($t\\) часов. "
                . "Какой путь он проедет?",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findDistanceCar(float $difficulty, Randomizer $rng): MathProblem
    {
        $v = $this->rnd($rng, 4, 12) * 10;
        $t = $this->rnd($rng, 2, 5);
        $s = $v * $t;

        $solution = "**Решение:**\n\n"
            . "Пройденный путь:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "s = v \\cdot t",
                "$s = $v \\cdot $t = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$s \\text{ км}");

        return new MathProblem(
            question: "Автомобиль движется со скоростью \\($v\\) км/ч и был в пути \\($t\\) часов. "
                . "Сколько километров он проехал?",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findTime(float $difficulty, Randomizer $rng): MathProblem
    {
        $v = $this->rnd($rng, 4, 12) * 10;
        $t = $this->rnd($rng, 2, 6);
        $s = $v * $t;

        $solution = "**Решение:**\n\n"
            . "Время равно пути, делённому на скорость:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "t = \\dfrac{s}{v}",
                "$t = \\dfrac{$s}{$v} = $t",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$t \\text{ ч}");

        return new MathProblem(
            question: "Поезд прошёл \\($s\\) км со скоростью \\($v\\) км/ч. "
                . "Сколько часов он был в пути?",
            solution: $solution,
            answer: (string) $t,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findTimeTrain(float $difficulty, Randomizer $rng): MathProblem
    {
        $v = $this->rnd($rng, 4, 12) * 10;
        $t = $this->rnd($rng, 2, 6);
        $s = $v * $t;

        $solution = "**Решение:**\n\n"
            . "Найдём время по формуле \\(t = \\dfrac{s}{v}\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "t = \\dfrac{$s}{$v}",
                (string) $t,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$t \\text{ ч}");

        return new MathProblem(
            question: "Расстояние \\($s\\) км машина проехала со скоростью \\($v\\) км/ч. "
                . "За сколько часов?",
            solution: $solution,
            answer: (string) $t,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findSpeed(float $difficulty, Randomizer $rng): MathProblem
    {
        $t = $this->rnd($rng, 2, 6);
        $v = $this->rnd($rng, 3, 12) * 10;
        $s = $v * $t;

        $solution = "**Решение:**\n\n"
            . "Скорость равна пути, делённому на время:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "v = \\dfrac{s}{t}",
                "$v = \\dfrac{$s}{$t} = $v",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$v \\text{ км/ч}");

        return new MathProblem(
            question: "За \\($t\\) часов турист прошёл \\($s\\) км. "
                . "С какой скоростью он шёл?",
            solution: $solution,
            answer: (string) $v,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function findSpeedSwimmer(float $difficulty, Randomizer $rng): MathProblem
    {
        $t = $this->rnd($rng, 2, 6);
        $v = $this->rnd($rng, 2, 9) * 10;
        $s = $v * $t;

        $solution = "**Решение:**\n\n"
            . "Скорость: \\(v = \\dfrac{s}{t}\\):\n\n"
            . KaTeX::display(KaTeX::aligned([
                "v = \\dfrac{$s}{$t}",
                (string) $v,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$v \\text{ км/ч}");

        return new MathProblem(
            question: "Пловец проплыл \\($s\\) км за \\($t\\) часов. "
                . "Какова его скорость?",
            solution: $solution,
            answer: (string) $v,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function combinedPath(float $difficulty, Randomizer $rng): MathProblem
    {
        $v = $this->rnd($rng, 3, 10) * 10;
        $t1 = $this->rnd($rng, 1, 3);
        $t2 = $this->rnd($rng, 1, 3);
        $s = $v * ($t1 + $t2);

        $solution = "**Решение:**\n\n"
            . "Найдём суммарное время и умножим на скорость:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "t = $t1 + $t2 = " . ($t1 + $t2) . " \\text{ ч}",
                "s = $v \\cdot " . ($t1 + $t2) . " = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$s \\text{ км}");

        return new MathProblem(
            question: "Велосипедист ехал \\($t1\\) ч, затем отдохнул и ехал ещё \\($t2\\) ч "
                . "с той же скоростью \\($v\\) км/ч. Какой путь он проехал всего?",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function meetingTime(float $difficulty, Randomizer $rng): MathProblem
    {
        $v1 = $this->rnd($rng, 2, 6) * 10;
        $v2 = $this->rnd($rng, 2, 6) * 10;
        $t = $this->rnd($rng, 2, 5);
        $s = ($v1 + $v2) * $t;

        $solution = "**Решение:**\n\n"
            . "Скорость сближения равна сумме скоростей. Время до встречи:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "t = \\dfrac{s}{v_1 + v_2}",
                "$t = \\dfrac{$s}{" . ($v1 + $v2) . "} = $t",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$t \\text{ ч}");

        return new MathProblem(
            question: "Из двух городов, расстояние между которыми \\($s\\) км, одновременно навстречу "
                . "друг другу выехали два автомобиля со скоростями \\($v1\\) и \\($v2\\) км/ч. "
                . "Через сколько часов они встретятся?",
            solution: $solution,
            answer: (string) $t,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function relativeSpeed(float $difficulty, Randomizer $rng): MathProblem
    {
        $v1 = $this->rnd($rng, 5, 9) * 10;
        $v2 = $this->rnd($rng, 2, 4) * 10;
        $t = $this->rnd($rng, 2, 5);
        $s = ($v1 - $v2) * $t;

        $solution = "**Решение:**\n\n"
            . "На сколько километров грузовик обгонит автомобиль:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "s = (v_1 - v_2) \\cdot t",
                "s = ($v1 - $v2) \\cdot $t",
                "$s = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$s \\text{ км}");

        return new MathProblem(
            question: "Грузовик едет со скоростью \\($v1\\) км/ч, а легковой автомобиль — \\($v2\\) км/ч. "
                . "На сколько километров грузовик обгонит автомобиль за \\($t\\) часов?",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function overtake(float $difficulty, Randomizer $rng): MathProblem
    {
        $vBig = $this->rnd($rng, 5, 9) * 10;
        $vDiff = $this->rnd($rng, 1, 3) * 10;
        $vSmall = $vBig - $vDiff;
        $t = $this->rnd($rng, 2, 5);
        $s = $vDiff * $t;

        $solution = "**Решение:**\n\n"
            . "Скорость удаления — разность скоростей \\($vDiff\\) км/ч. Расстояние между ними:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "s = ($vBig - $vSmall) \\cdot $t",
                "s = $vDiff \\cdot $t = $s",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$s \\text{ км}");

        return new MathProblem(
            question: "Мотоциклист едет со скоростью \\($vBig\\) км/ч, автомобиль — "
                . "\\($vSmall\\) км/ч. Какое расстояние будет между ними через \\($t\\) часов, "
                . "если они стартовали из одной точки?",
            solution: $solution,
            answer: (string) $s,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
