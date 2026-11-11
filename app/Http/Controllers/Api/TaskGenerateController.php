<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Infrastructure\Blocks\TestGenerator\Resolvers\TestResolverMap;
use App\Domain\Blocks\TestGenerator\Ports\GeneratorCatalogInterface;
use App\Domain\Blocks\TestGenerator\Ports\ProblemGeneratorInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GenerateMathTaskRequest;
use App\Http\Resources\MathProblemResource;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Генерация случайного задания.
 *
 * Поддерживает поиск по:
 *   1. topic          — прямой resolver-путь (например, 'math.algebra.equations.linearEquation')
 *   2. test_resolver  — адрес узла (например, 'math.algebra')
 *   3. taxonomy_id    — ID узла дерева
 *   4. subject        — если ничего не найдено, берёт случайный путь из указанного предмета
 *   5. Глобальный фолбэк — если для предмета нет тем, берёт любой доступный путь из всех генераторов
 *
 * Предмет в ответе всегда выводится из первого сегмента итогового resolver-пути,
 * поэтому системы с несколькими дисциплинами (math, physics, chemistry, ...)
 * возвращают корректный subject автоматически.
 */
final class TaskGenerateController extends Controller
{
    public function __invoke(
        GenerateMathTaskRequest $request,
        ProblemGeneratorInterface $testGenerator,
        GeneratorCatalogInterface $catalog,
        TestResolverMap $resolverMap,
    ): JsonResponse {
        $availablePaths = $catalog->availablePaths();
        $difficulty     = $request->resolvedDifficulty();

        $path = null;

        // 1) Прямой resolver-путь (topic).
        if (($direct = $request->input('topic')) !== null && $direct !== '') {
            if (! in_array($direct, $availablePaths, true)) {
                throw new UnprocessableEntityHttpException(
                    sprintf('Генератор "%s" недоступен. Доступные: %s.',
                        $direct, implode(', ', $availablePaths))
                );
            }
            $path = $direct;
        }

        // 2) Через test_resolver (точный путь).
        if ($path === null
            && ($tr = $request->input('test_resolver')) !== null
            && $tr !== ''
        ) {
            $path = $resolverMap->matchedPath((string) $tr);
        }

        // 3) Через taxonomy_id: поднимаемся по дереву до ближайшего test_resolver.
        if ($path === null
            && ($tid = $request->input('taxonomy_id')) !== null
            && is_numeric($tid)
        ) {
            $node  = Taxonomy::query()->find((int) $tid);
            $guard = 0;

            while ($node !== null && $guard++ < 64) {
                $resolver = is_string($node->test_resolver)
                    ? trim($node->test_resolver)
                    : '';

                if ($resolver !== '') {
                    $path = $resolverMap->matchedPath($resolver);
                    if ($path !== null) {
                        break;
                    }
                }

                if ($node->parent_id === null || $node->parent_id < 0) {
                    break;
                }
                $node = Taxonomy::query()->find($node->parent_id);
            }
        }

        // 4) Случайный путь, если ничего не задано.
        if ($path === null) {
            $requestedSubject = $request->resolvedSubject();

            $configuredTopics = $requestedSubject !== null
                ? array_keys((array) config("test_generator.subjects.{$requestedSubject}.topics", []))
                : [];

            $pool = $availablePaths;

            if ($requestedSubject !== null && $configuredTopics !== []) {
                $prefixes = array_map(
                    static fn (string $t): string => $requestedSubject . '.' . $t,
                    $configuredTopics,
                );

                // Короткие ключи конфига (arithmetic, algebra, ...) сопоставляем
                // по префиксу resolver-пути предмета.
                $pool = array_values(array_filter(
                    $availablePaths,
                    static function (string $p) use ($prefixes): bool {
                        foreach ($prefixes as $prefix) {
                            if ($p === $prefix || str_starts_with($p, $prefix . '.')) {
                                return true;
                            }
                        }
                        return false;
                    },
                ));

                // Если короткие ключи не совпали — берём все пути предмета.
                if ($pool === []) {
                    $pool = array_values(array_filter(
                        $availablePaths,
                        static fn (string $p): bool => $p === $requestedSubject
                            || str_starts_with($p, $requestedSubject . '.'),
                    ));
                }
            }

            if ($pool === []) {
                $pool = $availablePaths;
            }

            if ($pool === []) {
                throw new NotFoundHttpException('В генераторе нет ни одного доступного генератора.');
            }

            $path = $pool[array_rand($pool)];
        }

        $problem = $testGenerator->generate($path, $difficulty);

        // Предмет — первый сегмент resolver-пути ('physics.mechanics.x' → 'physics').
        // Ответ не «врёт» про предмет, даже если генератор выбран случайно.
        $subject = explode('.', $path, 2)[0];

        return (new MathProblemResource($problem, $subject))->response();
    }
}
