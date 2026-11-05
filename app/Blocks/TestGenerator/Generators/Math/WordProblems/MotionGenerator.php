<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\WordProblems;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Задачи на движение.
 *
 * difficulty:
 *   0.0–0.35  — расстояние: S = v · t
 *   0.35–0.55 — скорость:   v = S / t
 *   0.55–0.75 — время:      t = S / v
 *   0.75–1.00 — встречное:  S = (v₁ + v₂) · t
 *
 * Скорости и времена — целые; S получается целым.
 */
final class MotionGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.word_problems.motion.motion';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.35 => $this->distance($difficulty),
            $difficulty < 0.55 => $this->speed($difficulty),
            $difficulty < 0.75 => $this->time($difficulty),
            default            => $this->meeting($difficulty),
        };
    }

    private function distance(float $difficulty): MathProblem
    {
        $v = $this->rnd(3, 60);
        $t = $this->rnd(2, 8);
        $s = $v * $t;

        $solution = "**Решение:**\n\n"
            . "Скорость \\(v = $v\\) км/ч, время \\(t = $t\\) ч.\n\n"
            . "Формула пути:\n\n"
            . KaTeX::display('S = v \cdot t')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = $v \\cdot $t",
                "S = $s \\text{ км}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$s \\text{ км}");

        return new MathProblem(
            question: "Автомобиль едет со скоростью \\($v\\) км/ч в течение \\($t\\) ч. "
                . "Какое расстояние он проедет?",
            solution: $solution,
            answer: (string) $s,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function speed(float $difficulty): MathProblem
    {
        $v = $this->rnd(5, 80);
        $t = $this->rnd(2, 6);
        $s = $v * $t;

        $solution = "**Решение:**\n\n"
            . "Путь \\(S = $s\\) км, время \\(t = $t\\) ч.\n\n"
            . "Скорость — это путь, делённый на время:\n\n"
            . KaTeX::display('v = \dfrac{S}{t}')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "v = \\dfrac{$s}{$t}",
                "v = $v \\text{ км/ч}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$v \\text{ км/ч}");

        return new MathProblem(
            question: "Поезд прошёл \\($s\\) км за \\($t\\) ч. "
                . "С какой скоростью он двигался?",
            solution: $solution,
            answer: (string) $v,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function time(float $difficulty): MathProblem
    {
        $v = $this->rnd(5, 60);
        $t = $this->rnd(2, 7);
        $s = $v * $t;

        $solution = "**Решение:**\n\n"
            . "Путь \\(S = $s\\) км, скорость \\(v = $v\\) км/ч.\n\n"
            . "Время — это путь, делённый на скорость:\n\n"
            . KaTeX::display('t = \dfrac{S}{v}')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "t = \\dfrac{$s}{$v}",
                "t = $t \\text{ ч}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$t \\text{ ч}");

        return new MathProblem(
            question: "Велосипедист проехал \\($s\\) км со скоростью \\($v\\) км/ч. "
                . "Сколько времени он был в пути?",
            solution: $solution,
            answer: (string) $t,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function meeting(float $difficulty): MathProblem
    {
        $v1 = $this->rnd(20, 70);
        $v2 = $this->rnd(20, 70);
        while ($v1 === $v2) {
            $v2 = $this->rnd(20, 70);
        }
        $t = $this->rnd(2, 5);
        $s = ($v1 + $v2) * $t;

        $solution = "**Решение:**\n\n"
            . "Два объекта движутся навстречу друг другу. "
            . "Скорость их сближения:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "v_{\\text{сбл}} = v_1 + v_2",
                "v_{\\text{сбл}} = $v1 + $v2 = " . ($v1 + $v2) . " \\text{ км/ч}",
            ]))
            . "\n\nРасстояние между пунктами:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "S = v_{\\text{сбл}} \\cdot t",
                "S = " . ($v1 + $v2) . " \\cdot $t",
                "S = $s \\text{ км}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$s \\text{ км}");

        return new MathProblem(
            question: "Из двух городов одновременно навстречу друг другу выехали "
                . "два автомобиля со скоростями \\($v1\\) км/ч и \\($v2\\) км/ч. "
                . "Через \\($t\\) ч они встретились. Каково расстояние между городами?",
            solution: $solution,
            answer: (string) $s,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
