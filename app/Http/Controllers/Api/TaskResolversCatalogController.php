<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Blocks\TestGenerator\Ports\GeneratorCatalogInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Каталог резолверов заданий, сгруппированных по предметам.
 *
 * GET /api/catalog
 *
 * ── ИСТОЧНИКИ ДАННЫХ ─────────────────────────────────────────────────────
 *  - TestGenerator::availablePaths()  — что backend реально умеет
 *                                        (полные resolver-пути генераторов).
 *  - config('test_generator.subjects') — витрина: названия предметов
 *                                        и короткие ключи топиков для группировки.
 *
 * ── СОГЛАСОВАНИЕ ВИТРИНЫ И РЕАЛЬНОСТИ ───────────────────────────────────
 *  - короткий ключ топика в конфиге ('arithmetic') сопоставляется по ПРЕФИКСУ
 *    resolver-пути предмета ('math.arithmetic', 'math.arithmetic.numbers.counting');
 *  - тема в конфиге, но без генераторов      → скрывается;
 *  - генератор есть, но нет в конфиге        → попадает в «other»;
 *  - предмет без единой доступной темы       → не показывается.
 *
 * ── ФОРМАТ ОТВЕТА ────────────────────────────────────────────────────────
 *   {
 *     "default_subject": "math",
 *     "default_difficulty": 0.5,
 *     "subjects": [
 *       {
 *         "key": "math",
 *         "title": "Математика",
 *         "topics": [
 *           {"key": "arithmetic", "title": "Арифметика", "difficulty_range": [0,1]},
 *           ...
 *         ]
 *       }
 *     ]
 *   }
 */
final class TaskResolversCatalogController extends Controller
{
    public function __invoke(GeneratorCatalogInterface $testGenerator): JsonResponse
    {
        $availablePaths = $testGenerator->availablePaths();

        $subjects = [];
        $claimed  = [];

        /** @var array<string, array{title?:string, topics?:array<string, array{title?:string}>}> $configSubjects */
        $configSubjects = (array) config('test_generator.subjects', []);

        foreach ($configSubjects as $subjectKey => $subjectConfig) {
            $topicsOut = [];

            foreach ((array) ($subjectConfig['topics'] ?? []) as $topicKey => $topicConfig) {
                // Короткий ключ конфига ('arithmetic') сопоставляем по префиксу пути:
                //   math.arithmetic, math.arithmetic.numbers.counting, ...
                $prefix = (string) $subjectKey . '.' . $topicKey;

                $matched = array_values(array_filter(
                    $availablePaths,
                    static fn (string $p): bool => $p === $prefix
                        || str_starts_with($p, $prefix . '.'),
                ));

                if ($matched === []) {
                    continue; // тема в витрине есть, но генераторов нет — скрываем
                }

                foreach ($matched as $rowPath) {
                    $claimed[] = $rowPath;
                }

                $topicsOut[] = [
                    'key'              => $topicKey,
                    'title'            => (string) ($topicConfig['title'] ?? $topicKey),
                    'difficulty_range' => [0.0, 1.0],
                ];
            }

            if ($topicsOut === []) {
                continue; // пустой предмет не показываем
            }

            $subjects[] = [
                'key'    => (string) $subjectKey,
                'title'  => (string) ($subjectConfig['title'] ?? $subjectKey),
                'topics' => $topicsOut,
            ];
        }

        // Генераторы без записи в конфиге: не знаем предмет — складываем в «other».
        $orphans = array_values(array_diff($availablePaths, $claimed));
        if ($orphans !== []) {
            $topicsOut = array_map(static fn (string $key): array => [
                'key'              => $key,
                'title'            => $key,
                'difficulty_range' => [0.0, 1.0],
            ], $orphans);

            $subjects[] = [
                'key'    => 'other',
                'title'  => 'Прочее',
                'topics' => $topicsOut,
            ];
        }

        return response()->json([
            'default_subject'    => (string) config('test_generator.default_subject', 'math'),
            'default_difficulty' => (float)  config('test_generator.default_difficulty', 0.5),
            'subjects'           => $subjects,
        ]);
    }
}
