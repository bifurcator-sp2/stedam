<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Blocks\TestGenerator\TestGenerator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Каталог резолверов заданий, сгруппированных по предметам.
 *
 * GET /api/catalog
 *
 * в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђ
 * РСТОЧНРКР ДАННЫХ
 * в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђ
 *  - TestGenerator::availableTopics()  — «что backend реально умеет».
 *  - config('test_generator.subjects') — витрина: названия предметов
 *                                        и группировка топиков.
 *
 * в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђ
 * СОГЛАСОВАНРЕ ВРТРРНЫ Р РЕАЛЬНОСТР
 * в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђ
 *  - тема в конфиге, но нет генератора         → скрывается;
 *  - генератор есть, но темы нет в конфиге     → попадает в «other»;
 *  - предмет без единой доступной темы         → не показывается.
 *
 * в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђ
 * ФОРМАТ ОТВЕТА
 * в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђ
 *   {
 *     "default_subject": "math",
 *     "default_difficulty": 0.5,
 *     "subjects": [
 *       {
 *         "key": "math",
 *         "title": "Математика",
 *         "topics": [
 *           {"key": "arithmetic", "title": "Арифметика", "difficulty_range": [0,1]},
 *           {"key": "algebra",    "title": "Алгебра",    "difficulty_range": [0,1]},
 *           {"key": "geometry",   "title": "Геометрия",  "difficulty_range": [0,1]}
 *         ]
 *       }
 *     ]
 *   }
 *
 * Отличие от /api/resolvers: здесь витрина по предметам и топикам
 * (для выпадающих списков), без привязки к узлам дерева. /api/resolvers
 * — плоский список адресов test_resolver с метаданными о покрытии.
 */
final class TaskResolversCatalogController extends Controller
{
    public function __invoke(TestGenerator $testGenerator): JsonResponse
    {
        $availablePaths = $testGenerator->availablePaths();

        $subjects = [];
        $claimed  = [];

        /** @var array<string, array{title?:string, topics?:array<string, array{title?:string}>}> $configSubjects */
        $configSubjects = (array) config('test_generator.subjects', []);

        foreach ($configSubjects as $subjectKey => $subjectConfig) {
            $topicsOut = [];

            foreach ((array) ($subjectConfig['topics'] ?? []) as $topicKey => $topicConfig) {
                if (! in_array($topicKey, $availablePaths, true)) {
                    continue; // тема в витрине есть, но генератора нет — скрываем
                }

                $claimed[] = $topicKey;

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

        // Генераторы без записи в конфиге: не знаем, к какому предмету
        // отнести. Складываем в служебный предмет "other", чтобы не
        // терять их молча.
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
