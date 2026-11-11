<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Blocks\TestGenerator\Resolvers\TestResolverMap;
use App\Domain\Blocks\TestGenerator\TestGenerator;
use App\Http\Controllers\Controller;
use App\Http\Resources\MathProblemResource;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Подбор серии заданий «для обучения» по узлу дерева таксономий.
 *
 * GET /api/tasks/forlearn?taxonomy_id=5&count=5&difficulty=0.4
 *
 * Отличие от /tasks/random: там нужен ОДИН случайный генератор,
 * здесь — серия из пула генераторов, привязанных к узлу.
 *
 * ── ПОИСК РЕЗОЛВЕРА ──────────────────────────────────────────────────────
 *  - taxonomy_id обязателен: от узла поднимаемся по parent_id до ближайшего
 *    непустого test_resolver (наследование темы от родителя);
 *  - путь резолвера обязан существовать среди зарегистрированных генераторов.
 *
 * ── ВЫБОР СЛОЖНОСТИ ──────────────────────────────────────────────────────
 *  - difficulty задаётся явно — тогда все задания одной сложности;
 *  - иначе сложность растёт от base_difficulty по мере прохождения серии
 *    (шаг задаётся параметром step, по умолчанию 0.1), так что series —
 *    это «лестница» от простого к сложному.
 *
 * ── ФОРМАТ ОТВЕТА ────────────────────────────────────────────────────────
 *   {
 *     "resolver": "math.numberTheory.gcdLcm",
 *     "subject": "math",
 *     "taxonomy": {"id": 5, "title": "НОД и НОК"},
 *     "count": 5,
 *     "tasks": [ {question, solution, answer, topic, subject, difficulty}, ... ]
 *   }
 */
final class TaskForLearnController extends Controller
{
    /** Максимум заданий в одной серии. */
    private const MAX_COUNT = 20;

    public function __invoke(
        Request $request,
        TestGenerator $testGenerator,
        TestResolverMap $resolverMap,
    ): JsonResponse {
        $validated = $request->validate([
            'taxonomy_id' => ['required', 'integer', 'exists:taxonomies,id'],
            'count'       => ['sometimes', 'integer', 'between:1,' . self::MAX_COUNT],
            'difficulty'  => ['sometimes', 'numeric', 'between:0,1'],
            'base_difficulty' => ['sometimes', 'numeric', 'between:0,1'],
            'step'        => ['sometimes', 'numeric', 'between:0,0.5'],
        ]);

        $count = (int) ($validated['count'] ?? 5);
        $base  = (float) ($validated['base_difficulty']
            ?? config('test_generator.default_difficulty', 0.5));
        $step  = (float) ($validated['step'] ?? 0.1);

        $taxonomy = Taxonomy::query()
            ->with('translations')
            ->findOrFail((int) $validated['taxonomy_id']);

        $path = $this->resolvePath($taxonomy, $resolverMap);

        if (! $testGenerator->supports($path)) {
            throw new NotFoundHttpException(sprintf(
                'Для узла "%s" резолвер "%s" не зарегистрирован.',
                $this->taxonomyTitle($taxonomy),
                $path,
            ));
        }

        // Явная сложность — фиксированные задания; иначе «лестница» от base.
        $explicit = array_key_exists('difficulty', $validated)
            ? (float) $validated['difficulty']
            : null;

        $subject = explode('.', $path, 2)[0];

        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $difficulty = $explicit ?? $this->clamp01($base + $step * $i);

            $problem = $testGenerator->generate($path, $difficulty);

            $tasks[] = (new MathProblemResource($problem, $subject))->resolve($request);
        }

        return response()->json([
            'resolver' => $path,
            'subject'  => $subject,
            'taxonomy' => [
                'id'    => $taxonomy->id,
                'title' => $this->taxonomyTitle($taxonomy),
            ],
            'count'    => $count,
            'tasks'    => $tasks,
        ]);
    }

    /**
     * Ближайший test_resolver вверх по дереву, если у самого узла он пуст.
     *
     * Проверяется, что путь реальный (точное совпадение с генератором),
     * иначе продолжается подъём — так значение на родителе может быть
     * коротким префиксом и не является генератором.
     */
    private function resolvePath(Taxonomy $taxonomy, TestResolverMap $resolverMap): string
    {
        $node  = $taxonomy;
        $guard = 0;

        while ($node !== null && $guard++ < 64) {
            $resolver = is_string($node->test_resolver) ? trim($node->test_resolver) : '';

            if ($resolver !== '') {
                $matched = $resolverMap->matchedPath($resolver);
                if ($matched !== null) {
                    return $matched;
                }
            }

            if ($node->parent_id === null || $node->parent_id < 0) {
                break;
            }

            $node = Taxonomy::query()->find($node->parent_id);
        }

        throw new UnprocessableEntityHttpException(sprintf(
            'У узла "%s" и его родителей не задан действующий тестовый резолвер.',
            $this->taxonomyTitle($taxonomy),
        ));
    }

    private function taxonomyTitle(Taxonomy $taxonomy): string
    {
        $translation = $taxonomy->translations->first();

        $title = $translation?->title;
        if (is_string($title) && $title !== '') {
            return $title;
        }

        return (string) $taxonomy->id;
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
