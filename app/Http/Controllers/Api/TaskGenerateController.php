<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Blocks\TestGenerator\Resolvers\TestResolverMap;
use App\Blocks\TestGenerator\TestGenerator;
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
 *   1. topic          — прямой ключ (например, 'algebra')
 *   2. test_resolver  — адрес узла (например, 'math.algebra')
 *   3. taxonomy_id    — ID узла дерева
 *   4. subject        — если ничего не найдено, берет случайную тему из указанного предмета
 *   5. Глобальный фолбэк — если для предмета нет тем, берет ЛЮБУЮ доступную тему из всех генераторов
 */
final class TaskGenerateController extends Controller
{
    public function __invoke(
        GenerateMathTaskRequest $request,
        TestGenerator $testGenerator,
        TestResolverMap $resolverMap,
    ): JsonResponse {
        $subject = $request->resolvedSubject()
            ?? (string) config('test_generator.default_subject', 'math');

        $availableTopics = $testGenerator->availableTopics();
        $difficulty      = $request->resolvedDifficulty();

        $topic = null;

        // 1) Прямой topic.
        if (($direct = $request->input('topic')) !== null && $direct !== '') {
            if (! in_array($direct, $availableTopics, true)) {
                throw new UnprocessableEntityHttpException(
                    sprintf('Топик "%s" недоступен. Доступные: %s.',
                        $direct, implode(', ', $availableTopics))
                );
            }
            $topic = $direct;
        }

        // 2) Через test_resolver.
        if ($topic === null
            && ($tr = $request->input('test_resolver')) !== null
            && $tr !== ''
        ) {
            $topic = $resolverMap->topicFor((string) $tr);
        }

        // 3) Через taxonomy_id.
        if ($topic === null
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
                    $topic = $resolverMap->topicFor($resolver);
                    if ($topic !== null) {
                        break;
                    }
                }

                if ($node->parent_id === null || $node->parent_id < 0) {
                    break;
                }
                $node = Taxonomy::query()->find($node->parent_id);
            }
        }

        // 4) Если тема всё ещё не определена — ищем случайную.
        if ($topic === null) {
            // Сначала пробуем найти темы, привязанные к конкретному предмету в конфиге.
            $configured = array_keys((array) config("test_generator.subjects.{$subject}.topics", []));
            $pool = array_values(array_intersect($configured, $availableTopics));

            // Если для предмета ничего нет (как в вашем случае), берем ВСЕ доступные темы.
            if ($pool === []) {
                $pool = $availableTopics;
            }

            // Если вообще нет ни одного генератора в системе — 404.
            if ($pool === []) {
                throw new NotFoundHttpException('В системе нет доступных тем для генерации заданий.');
            }

            $topic = $pool[array_rand($pool)];
        }

        $problem = $testGenerator->generate($topic, $difficulty);

        return (new MathProblemResource($problem, $subject))->response();
    }
}
