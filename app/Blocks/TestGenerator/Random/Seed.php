<?php

declare(strict_types=1);

namespace App\Blocks\TestGenerator\Random;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Детерминированные сиды для генераторов заданий.
 *
 * Одна серия заданий идентифицируется парой (user_id, learn_id) — это
 * «основной сид». Каждое задание внутри серии получает собственный сид
 * от индекса, поэтому:
 *   - задание i не зависит от количества заданий и от порядка соседей;
 *   - добавив задание в конец, мы не меняем предыдущие;
 *   - тот же пользователь на том же прохождении получает ту же серию.
 *
 * При коллизии (два задания подряд вышли одинаковыми) используется
 * следующий attempt — сид меняется, задание генерируется заново.
 *
 * Формат сида: crc32("tg:v{schema}:{userId}:{learnId}:{index}") — 32 бита.
 * Префикс и разделитель обязательны: без них (12, 3) и (1, 23) дали бы
 * одинаковую строку "123".
 */
final class Seed
{
    /**
     * Версия схемы сида. Меняется, если поменялась логика генерации,
     * чтобы старые задания не «переехали» на новые значения.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Сид задания: (user_id, learn_id, index) → int32.
     */
    public static function forTask(int $userId, int $learnId, int $index): int
    {
        return self::hash($userId, $learnId, $index, 0);
    }

    /**
     * Сид конкретной попытки задания (attempt = 0 — канонический вариант).
     */
    public static function withAttempt(int $seed, int $attempt): int
    {
        return (int) (crc32('tg:attempt:' . $seed . ':' . $attempt) & 0xFFFFFFFF);
    }

    /**
     * Randomizer для задания по индексу и попытке.
     */
    public static function randomizer(int $userId, int $learnId, int $index, int $attempt = 0): Randomizer
    {
        return self::fromInt(self::hash($userId, $learnId, $index, $attempt));
    }

    /**
     * Randomizer из готового сида — для случая, когда сид уже вычислен
     * (например, повторная попытка через withAttempt()).
     */
    public static function fromInt(int $seed): Randomizer
    {
        // Mt19937 принимает int; нормализуем к неотрицательному для
        // предсказуемости между платформами (crc32 в PHP — uint32).
        return new Randomizer(new Mt19937($seed & 0xFFFFFFFF));
    }

    /**
     * Несидированный Randomizer — для обратной совместимости
     * (stateless-вызовы без детерминизма, например /tasks/random).
     */
    public static function unseeded(): Randomizer
    {
        return new Randomizer(new Mt19937(random_int(0, 0xFFFFFFFF)));
    }

    private static function hash(int $userId, int $learnId, int $index, int $attempt): int
    {
        $key = sprintf(
            'tg:v%d:%d:%d:%d:%d',
            self::SCHEMA_VERSION,
            $userId,
            $learnId,
            $index,
            $attempt,
        );

        return (int) (crc32($key) & 0xFFFFFFFF);
    }
}
