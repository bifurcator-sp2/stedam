<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Blocks\TestGenerator\Resolvers\TestResolverMap;
use App\Http\Controllers\Controller;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ResolversController extends Controller
{
    public function __invoke(
        Request $request,
        TestResolverMap $resolverMap,
    ): JsonResponse {
        $request->validate([
            'subject'         => ['sometimes', 'string', 'max:64'],
            'include_nodes'   => ['sometimes', 'boolean'],
            'include_orphans' => ['sometimes', 'boolean'],
        ]);

        $subjectFilter  = $request->input('subject');
        $includeNodes   = $request->boolean('include_nodes');
        $includeOrphans = $request->boolean('include_orphans');

        /** @var array<string, array{title?:string, topics?:array<string,array{title?:string}>}> $subjects */
        $subjects = (array) config('test_generator.subjects', []);

        /** @var Collection<string, Collection<int, Taxonomy>> $nodesByResolver */
        $nodesByResolver = Taxonomy::query()
            ->with('translations')
            ->whereNotNull('test_resolver')
            ->where('test_resolver', '!=', '')
            ->get()
            ->groupBy('test_resolver');

        $items     = [];
        $seenPaths = [];

        // Зарегистрированные резолверы (точные пути).
        foreach ($resolverMap->all() as $entry) {
            $path = $entry['path'];

            $subject = $resolverMap->subjectFor($path);
            if ($subjectFilter !== null && $subject !== $subjectFilter) {
                continue;
            }

            $seenPaths[$path] = true;

            $items[] = $this->buildItem(
                path:         $path,
                available:    true,
                subject:      $subject,
                subjects:     $subjects,
                nodeGroup:    $nodesByResolver->get($path),
                inConfig:     true,
                includeNodes: $includeNodes,
            );
        }

        // Пути из дерева, которых нет среди генераторов.
        $orphans = [];
        foreach ($nodesByResolver as $path => $nodeGroup) {
            if (isset($seenPaths[$path])) {
                continue;
            }

            $subject = $resolverMap->subjectFor((string) $path);
            if ($subjectFilter !== null && $subject !== $subjectFilter) {
                continue;
            }

            $item = $this->buildItem(
                path:         (string) $path,
                available:    $resolverMap->classFor((string) $path) !== null,
                subject:      $subject,
                subjects:     $subjects,
                nodeGroup:    $nodeGroup,
                inConfig:     false,
                includeNodes: $includeNodes,
            );
            $item['inherits_from'] = $resolverMap->matchedPath((string) $path);

            if ($includeOrphans) {
                $orphans[] = $item;
            }
        }

        usort($items,   static fn (array $a, array $b): int => strcmp($a['value'], $b['value']));
        usort($orphans, static fn (array $a, array $b): int => strcmp($a['value'], $b['value']));

        return response()->json([
            'default_subject'    => (string) config('test_generator.default_subject', 'math'),
            'default_difficulty' => (float)  config('test_generator.default_difficulty', 0.5),
            'resolvers'          => $items,
            'orphans_in_tree'    => $orphans,
        ]);
    }

    /**
     * @param Collection<int, Taxonomy>|null $nodeGroup
     * @param array<string, array{title?:string, topics?:array<string,array{title?:string}>}> $subjects
     * @return array<string, mixed>
     */
    private function buildItem(
        string $path,
        bool $available,
        string $subject,
        array $subjects,
        ?Collection $nodeGroup,
        bool $inConfig,
        bool $includeNodes,
    ): array {
        $segments = explode('.', $path);

        $item = [
            'value'        => $path,
            'label'        => $this->shortLabel($path, $subject, $subjects, $nodeGroup),
            'label_full'   => $this->fullLabel($path, $subject, $subjects, $nodeGroup),
            'segments'     => $segments,
            'subject'      => $subject,
            'topic'        => $path,
            'is_container' => false,
            'available'    => $available,
            'depth'        => count($segments),
            'in_config'    => $inConfig,
            'in_tree'      => $nodeGroup !== null && $nodeGroup->isNotEmpty(),
            'node_count'   => $nodeGroup?->count() ?? 0,
        ];

        if ($includeNodes && $nodeGroup !== null) {
            $item['nodes'] = $nodeGroup
                ->map(static fn (Taxonomy $n): array => [
                    'id'    => (int) $n->id,
                    'title' => $n->title,
                ])
                ->values()
                ->all();
        }

        return $item;
    }

    /**
     * @param Collection<int, Taxonomy>|null $nodeGroup
     * @param array<string, array{title?:string, topics?:array<string,array{title?:string}>}> $subjects
     */
    private function shortLabel(
        string $path,
        string $subject,
        array $subjects,
        ?Collection $nodeGroup,
    ): string {
        $nodeTitle = $nodeGroup?->first()?->title;
        if (is_string($nodeTitle) && $nodeTitle !== '') {
            return $nodeTitle;
        }

        $configTitle = $subjects[$subject]['topics'][$path]['title'] ?? null;
        if (is_string($configTitle) && $configTitle !== '') {
            return $configTitle;
        }

        $parts = explode('.', $path);
        $last  = (string) end($parts);

        return ucfirst(str_replace('_', ' ', $last));
    }

    /**
     * @param Collection<int, Taxonomy>|null $nodeGroup
     * @param array<string, array{title?:string, topics?:array<string,array{title?:string}>}> $subjects
     */
    private function fullLabel(
        string $path,
        string $subject,
        array $subjects,
        ?Collection $nodeGroup,
    ): string {
        $segments = explode('.', $path);
        $labels   = [];

        foreach ($segments as $i => $seg) {
            if ($i === count($segments) - 1) {
                $fromNode = $nodeGroup?->first()?->title;
                if (is_string($fromNode) && $fromNode !== '') {
                    $labels[] = $fromNode;
                    continue;
                }
            }

            if ($i === 0) {
                $subjectTitle = $subjects[$seg]['title'] ?? null;
                $labels[] = is_string($subjectTitle) && $subjectTitle !== ''
                    ? $subjectTitle
                    : ucfirst(str_replace('_', ' ', $seg));
                continue;
            }

            if ($i === 1) {
                $topicTitle = $subjects[$segments[0]]['topics'][$seg]['title'] ?? null;
                if (is_string($topicTitle) && $topicTitle !== '') {
                    $labels[] = $topicTitle;
                    continue;
                }
            }

            $labels[] = ucfirst(str_replace('_', ' ', $seg));
        }

        return implode('.', $labels);
    }
}