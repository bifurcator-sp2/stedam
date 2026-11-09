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
 * Р“РµРЅРµСЂР°С†РёСЏ СЃР»СѓС‡Р°Р№РЅРѕРіРѕ Р·Р°РґР°РЅРёСЏ.
 *
 * РџРѕРґРґРµСЂР¶РёРІР°РµС‚ РїРѕРёСЃРє РїРѕ:
 *   1. topic          вЂ” РїСЂСЏРСРѕР№ РєР»СЋС‡ (РЅР°РїСЂРёРСРµСЂ, 'algebra')
 *   2. test_resolver  вЂ” Р°РґСЂРµСЃ СѓР·Р»Р° (РЅР°РїСЂРёРСРµСЂ, 'math.algebra')
 *   3. taxonomy_id    вЂ” ID СѓР·Р»Р° РґРµСЂРµРІР°
 *   4. subject        вЂ” РµСЃР»Рё РЅРёС‡РµРіРѕ РЅРµ РЅР°Р№РґРµРЅРѕ, Р±РµСЂРµС‚ СЃР»СѓС‡Р°Р№РЅСѓСЋ С‚РµРССѓ РёР· СѓРєР°Р·Р°РЅРЅРѕРіРѕ РїСЂРµРґРСРµС‚Р°
 *   5. Р“Р»РѕР±Р°Р»СЊРЅС‹Р№ С„РѕР»Р±СЌРє вЂ” РµСЃР»Рё РґР»СЏ РїСЂРµРґРСРµС‚Р° РЅРµС‚ С‚РµРС, Р±РµСЂРµС‚ Р›Р®РвЂРЈР® РґРѕСЃС‚СѓРїРЅСѓСЋ С‚РµРССѓ РёР· РІСЃРµС… РіРµРЅРµСЂР°С‚РѕСЂРѕРІ
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

        $availablePaths = $testGenerator->availablePaths();
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
            $configured = array_keys((array) config("test_generator.subjects.{$subject}.topics", []));
            $pool = array_values(array_intersect($configured, $availablePaths));

            if ($pool === []) {
                $pool = $availablePaths;
            }

            if ($pool === []) {
                throw new NotFoundHttpException('В генераторе нет ни одного доступного генератора.');
            }

            $path = $pool[array_rand($pool)];
        }

        $problem = $testGenerator->generate($path, $difficulty);
        return (new MathProblemResource($problem, $subject))->response();
    }
}
