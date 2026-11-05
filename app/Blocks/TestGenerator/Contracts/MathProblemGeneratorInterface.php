<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Contracts;

use App\Blocks\TestGenerator\DTO\MathProblem;

/**
 * Контракт стратегии-генератора математических заданий.
 *
 * Каждая реализация обслуживает ровно одну тему (ключ, возвращаемый topic())
 * и умеет генерировать задания во всём диапазоне сложности 0.0 … 1.0.
 *
 * Требования к реализации:
 *  - generate() должен быть детерминированно-безопасен: не бросать исключений
 *    для difficulty в диапазоне [0.0, 1.0];
 *  - question и solution должны быть готовы к отображению через KaTeX
 *    auto-render (inline \\( ... \\), display \\[ ... \\]);
 *  - answer — простой текст (число, корень уравнения и т.п.) для машинной
 *    проверки и для пользовательского ввода.
 */
interface MathProblemGeneratorInterface
{
    /**
     * Сгенерировать задание заданной сложности.
     *
     * @param float $difficulty Сложность в диапазоне 0.0 (простое) … 1.0 (олимпиадное).
     *
     * @throws \InvalidArgumentException Если сложность вне диапазона [0.0, 1.0].
     */
    public function generate(float $difficulty): MathProblem;

    /**
     * Ключ темы, которую обслуживает генератор.
     *
     * Должен быть в нижнем регистре, без пробелов, стабилен во времени
     * (используется как ключ в реестре генераторов и во внешнем API).
     */
    public static function topic(): string;
}
