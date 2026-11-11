<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Generators\Math\WordProblems;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use App\Domain\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Domain\Blocks\TestGenerator\Support\KaTeX;
use Random\Randomizer;

/**
 * Задачи про деньги: стоимость, сдача, покупки.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — стоимость покупки (цена · количество)
 *   [0.2–0.4) — сдача
 *   [0.4–0.6) — общая стоимость нескольких товаров
 *   [0.6–0.8) — стоимость по цене и сдача с банкноты
 *   [0.8–1.0] — остаток бюджета / деление денег
 */
final class MoneyGenerator extends GeneratorBase
{
    protected function build(float $difficulty, Randomizer $rng): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick($rng, [
                fn () => $this->totalCost($difficulty, $rng),
                fn () => $this->applesCost($difficulty, $rng),
            ])(),
            $difficulty < 0.4 => $this->pick($rng, [
                fn () => $this->change($difficulty, $rng),
                fn () => $this->changeBills($difficulty, $rng),
            ])(),
            $difficulty < 0.6 => $this->pick($rng, [
                fn () => $this->severalItems($difficulty, $rng),
                fn () => $this->shoppingList($difficulty, $rng),
            ])(),
            $difficulty < 0.8 => $this->pick($rng, [
                fn () => $this->banknoteChange($difficulty, $rng),
                fn () => $this->priceAndChange($difficulty, $rng),
            ])(),
            default => $this->pick($rng, [
                fn () => $this->budgetLeft($difficulty, $rng),
                fn () => $this->splitMoney($difficulty, $rng),
            ])(),
        };
    }

    private function totalCost(float $difficulty, Randomizer $rng): MathProblem
    {
        $price = $this->rnd($rng, 20, 500);
        $count = $this->rnd($rng, 2, 9);
        $total = $price * $count;

        $solution = "**Решение:**\n\n"
            . "Стоимость = цена · количество:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$price \\cdot $count",
                (string) $total,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$total \\text{ ₽}");

        return new MathProblem(
            question: "Одна тетрадь стоит \\($price\\) ₽. Сколько стоят \\($count\\) таких тетрадей?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function applesCost(float $difficulty, Randomizer $rng): MathProblem
    {
        $price = $this->rnd($rng, 20, 200);
        $kg = $this->rnd($rng, 2, 9);
        $total = $price * $kg;

        $solution = "**Решение:**\n\n"
            . "Стоимость покупки:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$price \\cdot $kg",
                (string) $total,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$total \\text{ ₽}");

        return new MathProblem(
            question: "Яблоки стоят \\($price\\) ₽ за килограмм. Сколько стоят \\($kg\\) кг?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function change(float $difficulty, Randomizer $rng): MathProblem
    {
        $price = $this->rnd($rng, 20, 400);
        $paid = $price + $this->rnd($rng, 10, 300);
        $change = $paid - $price;

        $solution = "**Решение:**\n\n"
            . "Сдача = уплачено − стоимость:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$paid - $price",
                (string) $change,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$change \\text{ ₽}");

        return new MathProblem(
            question: "Покупка стоила \\($price\\) ₽. Покупатель заплатил \\($paid\\) ₽. "
                . "Сколько сдачи он получит?",
            solution: $solution,
            answer: (string) $change,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function changeBills(float $difficulty, Randomizer $rng): MathProblem
    {
        $price = $this->rnd($rng, 50, 300);
        $paid = $this->pick($rng, [500, 1000]);
        $change = $paid - $price;

        $solution = "**Решение:**\n\n"
            . "Сдача:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$paid - $price",
                (string) $change,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$change \\text{ ₽}");

        return new MathProblem(
            question: "Товар стоит \\($price\\) ₽. За него заплатили купюрой \\($paid\\) ₽. "
                . "Какую сдачу получат?",
            solution: $solution,
            answer: (string) $change,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function severalItems(float $difficulty, Randomizer $rng): MathProblem
    {
        $p1 = $this->rnd($rng, 20, 300);
        $p2 = $this->rnd($rng, 20, 300);
        $total = $p1 + $p2;

        $solution = "**Решение:**\n\n"
            . "Сложим стоимости товаров:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$p1 + $p2",
                (string) $total,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$total \\text{ ₽}");

        return new MathProblem(
            question: "Купили книгу за \\($p1\\) ₽ и ручку за \\($p2\\) ₽. "
                . "Сколько всего потратили?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function shoppingList(float $difficulty, Randomizer $rng): MathProblem
    {
        $p1 = $this->rnd($rng, 50, 200);
        $c1 = $this->rnd($rng, 2, 5);
        $p2 = $this->rnd($rng, 50, 200);
        $total = $p1 * $c1 + $p2;

        $solution = "**Решение:**\n\n"
            . "Сначала стоимость первого товара, затем добавим второй:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$p1 \\cdot $c1 + $p2",
                (string) ($p1 * $c1) . " + $p2",
                (string) $total,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$total \\text{ ₽}");

        return new MathProblem(
            question: "Купили \\($c1\\) штук по \\($p1\\) ₽ и ещё один товар за \\($p2\\) ₽. "
                . "Сколько денег потратили?",
            solution: $solution,
            answer: (string) $total,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function banknoteChange(float $difficulty, Randomizer $rng): MathProblem
    {
        $price = $this->rnd($rng, 100, 900);
        $paid = $this->pick($rng, [1000, 2000]);
        $change = $paid - $price;

        $solution = "**Решение:**\n\n"
            . "Сдача:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$paid - $price",
                (string) $change,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$change \\text{ ₽}");

        return new MathProblem(
            question: "Покупка обошлась в \\($price\\) ₽, а расплатились банкнотой \\($paid\\) ₽. "
                . "Какова сдача?",
            solution: $solution,
            answer: (string) $change,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function priceAndChange(float $difficulty, Randomizer $rng): MathProblem
    {
        $price = $this->rnd($rng, 100, 900);
        $count = $this->rnd($rng, 2, 3);
        $paid = $this->pick($rng, [1000, 2000, 5000]);
        while ($paid < $price * $count) {
            $paid += 1000;
        }
        $cost = $price * $count;
        $change = $paid - $cost;

        $solution = "**Решение:**\n\n"
            . "Стоимость всей покупки и сдача:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$price \\cdot $count = $cost",
                "$paid - $cost = $change",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$change \\text{ ₽}");

        return new MathProblem(
            question: "Купили \\($count\\) товара(-ов) по \\($price\\) ₽ и заплатили \\($paid\\) ₽. "
                . "Сколько сдачи останется?",
            solution: $solution,
            answer: (string) $change,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function budgetLeft(float $difficulty, Randomizer $rng): MathProblem
    {
        $budget = $this->rnd($rng, 5, 20) * 100;
        $spent1 = $this->rnd($rng, 100, intdiv($budget, 2));
        $spent2 = $this->rnd($rng, 100, intdiv($budget, 2));
        $left = $budget - $spent1 - $spent2;

        $solution = "**Решение:**\n\n"
            . "Вычтем из бюджета все расходы:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$budget - $spent1 - $spent2",
                (string) ($budget - $spent1) . " - $spent2",
                (string) $left,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$left \\text{ ₽}");

        return new MathProblem(
            question: "Было \\($budget\\) ₽. Потратили \\($spent1\\) ₽, затем ещё \\($spent2\\) ₽. "
                . "Сколько денег осталось?",
            solution: $solution,
            answer: (string) $left,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function splitMoney(float $difficulty, Randomizer $rng): MathProblem
    {
        $people = $this->rnd($rng, 2, 6);
        $each = $this->rnd($rng, 100, 600);
        $total = $people * $each;

        $solution = "**Решение:**\n\n"
            . "Делим общую сумму на количество человек:\n\n"
            . KaTeX::display(KaTeX::aligned([
                "$total \\div $people",
                (string) $each,
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$each \\text{ ₽}");

        return new MathProblem(
            question: "\\($total\\) ₽ разделили поровну между \\($people\\) людьми. "
                . "Сколько получил каждый?",
            solution: $solution,
            answer: (string) $each,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
