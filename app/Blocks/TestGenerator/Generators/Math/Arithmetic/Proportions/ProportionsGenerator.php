<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Generators\Math\Arithmetic\Proportions;

use App\Blocks\TestGenerator\DTO\MathProblem;
use App\Blocks\TestGenerator\Generators\GeneratorBase;
use App\Blocks\TestGenerator\Support\KaTeX;

/**
 * Пропорции: решение уравнений и прямые пропорциональные зависимости.
 *
 * Пять уровней сложности, на каждом — по две формулировки:
 *   [0.0–0.2) — решить a/b = c/x (малые числа)
 *   [0.2–0.4) — простая стоимость
 *   [0.4–0.6) — решить пропорцию с бо́льшими числами
 *   [0.6–0.8) — пропорциональная зависимость (рецепт)
 *   [0.8–1.0] — пропорциональная зависимость (зарплата/скорость)
 */
final class ProportionsGenerator extends GeneratorBase
{
    protected function build(float $difficulty): MathProblem
    {
        return match (true) {
            $difficulty < 0.2 => $this->pick([
                fn () => $this->solveSmall($difficulty),
                fn () => $this->mapDistance($difficulty),
            ])(),
            $difficulty < 0.4 => $this->pick([
                fn () => $this->priceSimple($difficulty),
                fn () => $this->candySimple($difficulty),
            ])(),
            $difficulty < 0.6 => $this->pick([
                fn () => $this->solveBig($difficulty),
                fn () => $this->paintSimple($difficulty),
            ])(),
            $difficulty < 0.8 => $this->pick([
                fn () => $this->recipe($difficulty),
                fn () => $this->carpenter($difficulty),
            ])(),
            default => $this->pick([
                fn () => $this->salary($difficulty),
                fn () => $this->speedTime($difficulty),
            ])(),
        };
    }

    private function solveSmall(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 9);
        $x = $this->rnd(2, 12);
        $c = $a * $this->rnd(2, 9);
        $b = $this->rnd(2, 12);

        $solution = "**Решение:**\n\n"
            . "По основному свойству пропорции произведение крайних равно произведению средних:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$a}{$b} = \\dfrac{$c}{x} \\\\",
                "&$a \\cdot x = $b \\cdot $c \\\\",
                "&x = \\dfrac{{$b} \\cdot {$c}}{$a} = $x",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x = $x");

        return new MathProblem(
            question: "Решите пропорцию: " . KaTeX::inline("\\dfrac{$a}{$b} = \\dfrac{$c}{x}"),
            solution: $solution,
            answer: "x = $x",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function mapDistance(float $difficulty): MathProblem
    {
        $a = $this->rnd(2, 9);
        $x = $this->rnd(2, 12);
        $c = $a * $this->rnd(2, 9);
        $b = $this->rnd(2, 12);

        $solution = "**Решение:**\n\n"
            . "Масштаб карты задаёт пропорцию:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$a}{$b} = \\dfrac{$c}{x} \\\\",
                "&$a \\cdot x = $b \\cdot $c \\\\",
                "&x = $x",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x = $x");

        return new MathProblem(
            question: "\\($a\\) см на карте соответствуют \\($b\\) км на местности. "
                . "Сколько километров соответствуют \\($c\\) см? Составьте пропорцию.",
            solution: $solution,
            answer: "x = $x",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function priceSimple(float $difficulty): MathProblem
    {
        $pricePerKg = $this->rnd(20, 200);
        $k = $this->rnd(2, 8);
        $n = $this->rnd(2, 10);
        while ($n === $k) {
            $n = $this->rnd(2, 10);
        }
        $m = $k * $pricePerKg;
        $target = $n * $pricePerKg;

        $solution = "**Решение:**\n\n"
            . "Запишем пропорцию: \\($k\\) кг — \\($m\\) ₽, \\($n\\) кг — \\(x\\) ₽.\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$k}{$n} = \\dfrac{$m}{x} \\\\",
                "&$k \\cdot x = $n \\cdot $m \\\\",
                "&x = $target",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$target \\text{ ₽}");

        return new MathProblem(
            question: "\\($k\\) кг товара стоят \\($m\\) ₽. Сколько стоят \\($n\\) кг?",
            solution: $solution,
            answer: (string) $target,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function candySimple(float $difficulty): MathProblem
    {
        $perBox = $this->rnd(5, 30);
        $k = $this->rnd(2, 8);
        $n = $this->rnd(2, 10);
        while ($n === $k) {
            $n = $this->rnd(2, 10);
        }
        $m = $k * $perBox;
        $target = $n * $perBox;

        $solution = "**Решение:**\n\n"
            . "\\($k\\) коробок — \\($m\\) конфет, \\($n\\) коробок — \\(x\\) конфет:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$k}{$n} = \\dfrac{$m}{x} \\\\",
                "&$k \\cdot x = $n \\cdot $m \\\\",
                "&x = $target",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $target);

        return new MathProblem(
            question: "В \\($k\\) коробках \\($m\\) конфет. Сколько конфет в \\($n\\) таких коробках?",
            solution: $solution,
            answer: (string) $target,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function solveBig(float $difficulty): MathProblem
    {
        $a = $this->rnd(5, 25);
        $x = $this->rnd(4, 30);
        $c = $a * $this->rnd(2, 6);
        $b = $this->rnd(5, 25);

        $solution = "**Решение:**\n\n"
            . "Перемножим крест-накрест:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$a}{$b} = \\dfrac{$c}{x} \\\\",
                "&$a \\cdot x = $b \\cdot $c = " . ($b * $c) . " \\\\",
                "&x = $x",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("x = $x");

        return new MathProblem(
            question: "Найдите \\(x\\) из пропорции: " . KaTeX::inline("\\dfrac{$a}{$b} = \\dfrac{$c}{x}"),
            solution: $solution,
            answer: "x = $x",
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function paintSimple(float $difficulty): MathProblem
    {
        $cans = $this->rnd(2, 8);
        $area = $cans * $this->rnd(5, 20);
        $n = $this->rnd(3, 12);
        while ($n === $cans) {
            $n = $this->rnd(3, 12);
        }
        $target = ($area / $cans) * $n;

        $solution = "**Решение:**\n\n"
            . "Количество краски пропорционально площади:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$cans}{$n} = \\dfrac{$area}{x} \\\\",
                "&$cans \\cdot x = $n \\cdot $area \\\\",
                "&x = $target",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$target \\text{ м}^2");

        return new MathProblem(
            question: "\\($cans\\) банок краски хватает на \\($area\\) м². "
                . "На сколько квадратных метров хватит \\($n\\) банок?",
            solution: $solution,
            answer: (string) $target,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function recipe(float $difficulty): MathProblem
    {
        $servings = $this->rnd(2, 8);
        $grams = $servings * $this->rnd(10, 40);
        $n = $this->rnd(3, 15);
        while ($n === $servings) {
            $n = $this->rnd(3, 15);
        }
        $target = ($grams / $servings) * $n;

        $solution = "**Решение:**\n\n"
            . "Пропорция для рецепта:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$servings}{$n} = \\dfrac{$grams}{x} \\\\",
                "&$servings \\cdot x = $n \\cdot $grams \\\\",
                "&x = $target",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$target \\text{ г}");

        return new MathProblem(
            question: "На \\($servings\\) порций нужно \\($grams\\) г крупы. "
                . "Сколько крупы нужно на \\($n\\) порций?",
            solution: $solution,
            answer: (string) $target,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function carpenter($difficulty): MathProblem
    {
        $days = $this->rnd(2, 8);
        $chairs = $days * $this->rnd(5, 20);
        $n = $this->rnd(3, 15);
        while ($n === $days) {
            $n = $this->rnd(3, 15);
        }
        $target = ($chairs / $days) * $n;

        $solution = "**Решение:**\n\n"
            . "Производительность постоянна, составим пропорцию:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$days}{$n} = \\dfrac{$chairs}{x} \\\\",
                "&$days \\cdot x = $n \\cdot $chairs \\\\",
                "&x = $target",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline((string) $target);

        return new MathProblem(
            question: "Столяр делает \\($chairs\\) стульев за \\($days\\) дней. "
                . "Сколько стульев он сделает за \\($n\\) дней при той же скорости?",
            solution: $solution,
            answer: (string) $target,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function salary(float $difficulty): MathProblem
    {
        $hours = $this->rnd(4, 10);
        $pay = $hours * $this->rnd(100, 500);
        $n = $this->rnd(4, 20);
        while ($n === $hours) {
            $n = $this->rnd(4, 20);
        }
        $target = ($pay / $hours) * $n;

        $solution = "**Решение:**\n\n"
            . "Оплата пропорциональна отработанным часам:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$hours}{$n} = \\dfrac{$pay}{x} \\\\",
                "&$hours \\cdot x = $n \\cdot $pay \\\\",
                "&x = $target",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$target \\text{ ₽}");

        return new MathProblem(
            question: "За \\($hours\\) часов работы заплатили \\($pay\\) ₽. "
                . "Сколько заплатят за \\($n\\) часов при той же оплате?",
            solution: $solution,
            answer: (string) $target,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }

    private function speedTime(float $difficulty): MathProblem
    {
        $speed = $this->rnd(4, 20);
        $time = $this->rnd(2, 10);
        $dist = $speed * $time;
        $n = $this->rnd(2, 12);
        while ($n === $speed) {
            $n = $this->rnd(2, 12);
        }
        $target = ($dist / $speed) * $n;

        $solution = "**Решение:**\n\n"
            . "Путь при постоянной скорости пропорционален времени:\n\n"
            . KaTeX::display(KaTeX::alignedRaw([
                "&\\dfrac{$time}{$n} = \\dfrac{$dist}{x} \\\\",
                "&$time \\cdot x = $n \\cdot $dist \\\\",
                "&x = $target",
            ]))
            . "\n\n**Ответ:** " . KaTeX::inline("$target \\text{ км}");

        return new MathProblem(
            question: "Велосипедист проехал \\($dist\\) км за \\($time\\) ч. "
                . "Сколько километров он проедет за \\($n\\) ч при той же скорости?",
            solution: $solution,
            answer: (string) $target,
            topic: $this->path(),
            difficulty: $difficulty,
        );
    }
}
