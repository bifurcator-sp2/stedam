<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Ports;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use Random\Randomizer;

/**
 * Порт генерации учебного задания по resolver-пути.
 *
 * Это точка, через которую домен получает сгенерированное задание.
 * Реализация — в инфраструктуре (сейчас файловое discovery по Generators/),
 * поэтому способ хранения и обнаружения генераторов можно менять,
 * не трогая ни домен, ни HTTP-слой.
 *
 * Контракт: один и тот же путь + сложность + сид дают одно и то же задание.
 */
interface ProblemGeneratorInterface
{
    /**
     * Сгенерировать задание по resolver-пути.
     *
     * @param string          $path       например 'math.algebra.equations.linearEquation'
     * @param float           $difficulty от 0.0 до 1.0
     * @param Randomizer|null $rng        сидированный источник случайности; null — несидированный.
     *
     * @throws \InvalidArgumentException если difficulty вне [0.0, 1.0] или путь не зарегистрирован
     */
    public function generate(string $path, float $difficulty, ?Randomizer $rng = null): MathProblem;

    /**
     * Есть ли генератор по данному пути.
     */
    public function supports(string $path): bool;
}
