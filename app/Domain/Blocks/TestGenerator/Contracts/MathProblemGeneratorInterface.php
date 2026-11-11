<?php

declare(strict_types=1);

namespace App\Domain\Blocks\TestGenerator\Contracts;

use App\Domain\Blocks\TestGenerator\DTO\MathProblem;
use Random\Randomizer;

/**
 * Контракт генератора учебных заданий: генерация MathProblem.
 *
 * Идентификатор генератора — его resolver-путь, вычисляемый из расположения
 * файла в Generators/файлах (см. GeneratorDiscovery / PathResolver).
 * Отдельного метода topic() нет: путь и есть идентификатор.
 *
 * generate() обязан построить корректный объект:
 *  - валидировать difficulty в диапазоне [0.0, 1.0];
 *  - question и solution — в разметке KaTeX auto-render (inline \( ... \), display \[ ... \]);
 *  - answer — строка-ответ для проверки учеником.
 *
 * Вся случайность берётся ТОЛЬКО из переданного $rng. Это позволяет
 * воспроизводить задания по сиду (см. Random\Seed).
 */
interface MathProblemGeneratorInterface
{
    /**
     * Сгенерировать экземпляр задания по сложности.
     *
     * @param float           $difficulty от 0.0 (просто) до 1.0 (сложно).
     * @param Randomizer|null $rng        источник псевдослучайности; один и тот же
     *                                    сид даёт одно и то же задание. null —
     *                                    несидированный RNG (случайный результат).
     *
     * @throws \InvalidArgumentException если difficulty вне [0.0, 1.0].
     */
    public function generate(float $difficulty, ?Randomizer $rng = null): MathProblem;
}
