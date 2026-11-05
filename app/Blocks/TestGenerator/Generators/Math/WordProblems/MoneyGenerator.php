<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\WordProblems;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Задачи на покупки: цена × количество = стоимость.
 *
 * difficulty:
 *   0.0–0.30  — стоимость: C = p · q
 *   0.30–0.55 — цена:      p = C / q
 *   0.55–0.75 — количество: q = C / p
 *   0.75–1.00 — сдача / скидка
 */
final class MoneyGenerator extends GeneratorBase
{
    public static function topic(): string
    {
        return 'math.word_problems.money.money';
    }

    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.30 => $this->cost($difficulty),
            $difficulty < 0.55 => $this->price($difficulty),
            $difficulty < 0.75 => $this->quantity($difficulty),
            default            => $this->change($difficulty),
        };
    }

    private function cost(float $difficulty): MathProblem
    {
        $p = $this->rnd(15, 200);
        $q = $this->rnd(2, 12);
        $c = $p * $q;

        $solution = "**Решение:**\n\n"
            . "Цена \\(p = $p\\) ₽, количество \\(q = $q\\) шт.\n\n"
            . "Стоимость покупки:\n\n"
            . KaTeX::display('C = p \cdot q')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "C = $p \\cdot $q",
                "C = $c \\text{ ₽}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$c \\text{ ₽}");

        return new MathProblem(
            question: "Одна тетрадь стоит \\($p\\) ₽. Купили \\($q\\) тетрадей. "
                . "Сколько заплатили?",
            solution: $solution,
            answer: (string) $c,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function price(float $difficulty): MathProblem
    {
        $p = $this->rnd(15, 250);
        $q = $this->rnd(2, 10);
        $c = $p * $q;

        $solution = "**Решение:**\n\n"
            . "Всего заплатили \\(C = $c\\) ₽ за \\(q = $q\\) штук.\n\n"
            . "Цена одной штуки:\n\n"
            . KaTeX::display('p = \dfrac{C}{q}')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "p = \\dfrac{$c}{$q}",
                "p = $p \\text{ ₽}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$p \\text{ ₽}");

        return new MathProblem(
            question: "За \\($q\\) одинаковых ручек заплатили \\($c\\) ₽. "
                . "Сколько стоит одна ручка?",
            solution: $solution,
            answer: (string) $p,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function quantity(float $difficulty): MathProblem
    {
        $p = $this->rnd(10, 150);
        $q = $this->rnd(2, 12);
        $c = $p * $q;

        $solution = "**Решение:**\n\n"
            . "Цена \\(p = $p\\) ₽, вся покупка стоит \\(C = $c\\) ₽.\n\n"
            . "Количество:\n\n"
            . KaTeX::display('q = \dfrac{C}{p}')
            . "\n\nПодставим:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "q = \\dfrac{$c}{$p}",
                "q = $q \\text{ шт.}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$q \\text{ шт.}");

        return new MathProblem(
            question: "Один альбом стоит \\($p\\) ₽. Сколько альбомов можно купить "
                . "на \\($c\\) ₽?",
            solution: $solution,
            answer: (string) $q,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }

    private function change(float $difficulty): MathProblem
    {
        $p = $this->rnd(20, 300);
        $q = $this->rnd(2, 6);
        $c = $p * $q;
        // Даём купюру заведомо больше стоимости.
        $paid = $c + $this->rnd(50, 500);
        $change = $paid - $c;

        $solution = "**Решение:**\n\n"
            . "Стоимость покупки:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "C = $p \\cdot $q = $c \\text{ ₽}",
            ]))
            . "\n\nСдача — это разность между отданной суммой и стоимостью:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "\\text{сдача} = $paid - $c",
                "\\text{сдача} = $change \\text{ ₽}",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$change \\text{ ₽}");

        return new MathProblem(
            question: "Купили \\($q\\) товаров по \\($p\\) ₽. Кассиру дали \\($paid\\) ₽. "
                . "Сколько сдачи получит покупатель?",
            solution: $solution,
            answer: (string) $change,
            topic: self::topic(),
            difficulty: $difficulty,
        );
    }
}
