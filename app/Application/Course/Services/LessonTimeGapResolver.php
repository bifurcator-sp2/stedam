<?php

declare(strict_types=1);

namespace App\Application\Course\Services;

use App\Application\Course\Ports\LessonReaderInterface;
use App\Domain\Course\ValueObjects\LessonId;
use App\Domain\Course\ValueObjects\TimeGap;
use App\Domain\Course\ValueObjects\TimeGapMultiplier;

/**
 * Разрыв между уроками — производная от типа урока и множителя курса:
 *
 *   time_gap = ceil(lesson_types.time_gap × course.time_gap_multipler)
 *
 * Цепочка: lessonId → lessons.type (код) → lesson_types.time_gap (часы).
 * Клиент число не присылает: оно зависит от справочника и множителя, и
 * считать его на фронте — гарантированный рассинхрон.
 *
 * Округление:
 *  - результат в (0, 1) — 1: «почти час» округляется до часа, чтобы урок
 *    не «прилипал» к предыдущему;
 *  - результат > 1 — ceil, агрессивно вверх: пауза не может быть короче
 *    требуемой;
 *  - ровно 0 (множитель 0 или time_gap 0) — 0: «без паузы».
 *
 * Если тип урока не найден в справочнике (код переименован, урок без типа)
 * или урок не найден вовсе, берётся fallback — значение, пришедшее от
 * клиента. Так сохранение курса не падает из-за справочника.
 */
final readonly class LessonTimeGapResolver
{
    public function __construct(
        private LessonReaderInterface $lessons,
    ) {}

    /**
     * Разрывы для пачки уроков.
     *
     * @param list<LessonId> $lessonIds
     * @param array<int, int> $fallbacks lessonId => часы, если расчёт невозможен
     * @return array<int, TimeGap> lessonId => готовый разрыв
     */
    public function resolveMany(
        array $lessonIds,
        TimeGapMultiplier $multiplier,
        array $fallbacks = [],
    ): array {
        if ($lessonIds === []) {
            return [];
        }

        $codesByLesson = $this->lessons->typeCodesOf($lessonIds);

        $codes = [];
        foreach ($codesByLesson as $code) {
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        $gapsByCode = $this->lessons->timeGapsByTypeCode($codes);

        $result = [];

        foreach ($lessonIds as $lessonId) {
            $code = $codesByLesson[$lessonId->value] ?? null;
            $base = $code === null ? null : ($gapsByCode[$code] ?? null);

            $result[$lessonId->value] = $base === null
                ? $this->fallback($lessonId, $fallbacks)
                : $this->derive($base, $multiplier);
        }

        return $result;
    }

    public function resolve(
        LessonId $lessonId,
        TimeGapMultiplier $multiplier,
        ?int $fallback = null,
    ): TimeGap {
        return $this->resolveMany(
            [$lessonId],
            $multiplier,
            $fallback === null ? [] : [$lessonId->value => $fallback],
        )[$lessonId->value];
    }

    private function derive(int $baseHours, TimeGapMultiplier $multiplier): TimeGap
    {
        // round до 1e-6 убирает «плавающий хвост» double: 12 * 1.05 в double
        // даёт 12.600000000000001, а ceil такого хвоста поднимал бы целое
        // значение на единицу без причины.
        $value = round($baseHours * $multiplier->value, 6);

        if ($value <= 0.0) {
            return TimeGap::zero();
        }

        return new TimeGap((int) ceil($value));
    }

    /**
     * @param array<int, int> $fallbacks
     */
    private function fallback(LessonId $lessonId, array $fallbacks): TimeGap
    {
        $hours = $fallbacks[$lessonId->value] ?? null;

        return $hours === null ? TimeGap::default() : new TimeGap(max(0, $hours));
    }
}
