<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Blocks\TestGenerator\Resolvers\TestResolverMap;
use App\Blocks\TestGenerator\TestGenerator;
use App\Http\Controllers\Controller;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ResolversController extends Controller
{
    public function __invoke(
        Request $request,
        TestGenerator $generators,
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

        $items = [];
        $seenPaths = [];

        // Основной список — из автообнаруженных путей.
        foreach ($resolverMap->all() as $entry) {
            $path  = $entry['path'];
            $topic = $entry['topic'];

            $subject = $resolverMap->subjectFor($path);
            if ($subjectFilter !== null && $subject !== $subjectFilter) {
                continue;
            }

            $seenPaths[$path] = true;

            $items[] = $this->buildItem(
                path:         $path,
                topic:        $topic,
                subject:      $subject,
                subjects:     $subjects,
                nodeGroup:    $nodesByResolver->get($path),
                inConfig:     true,
                available:    $generators->supports($topic),
                includeNodes: $includeNodes,
            );
        }

        // Сироты в дереве — узлы, чей test_resolver не покрыт ни одним
        // зарегистрированным путём. Это нормальный кейс: узел размечен,
        // но генератора ещё нет (или он унаследует от предка, если
        // предок зарегистрирован).
        $orphans = [];
        foreach ($nodesByResolver as $path => $nodeGroup) {
            if (isset($seenPaths[$path])) {
                continue;
            }

            $subject = $resolverMap->subjectFor((string) $path);
            if ($subjectFilter !== null && $subject !== $subjectFilter) {
                continue;
            }

            $resolvedTopic = $resolverMap->topicFor((string) $path);
            $matchedPath   = $resolverMap->matchedPath((string) $path);
            $available     = $resolvedTopic !== null && $generators->supports($resolvedTopic);

            $item = $this->buildItem(
                path:         (string) $path,
                topic:        $resolvedTopic,
                subject:      $subject,
                subjects:     $subjects,
                nodeGroup:    $nodeGroup,
                inConfig:     false,
                available:    $available,
                includeNodes: $includeNodes,
            );
            $item['inherits_from'] = $matchedPath;

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
        ?string $topic,
        string $subject,
        array $subjects,
        ?Collection $nodeGroup,
        bool $inConfig,
        bool $available,
        bool $includeNodes,
    ): array {
        $segments = explode('.', $path);
        $isContainer = $topic === null;

        $item = [
            'value'        => $path,
            'label'        => $this->shortLabel($path, $topic, $subject, $subjects, $nodeGroup),
            'label_full'   => $this->fullLabel($path, $subject, $subjects, $nodeGroup),
            'segments'     => $segments,
            'subject'      => $subject,
            'topic'        => $topic,
            'is_container' => $isContainer,
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
        ?string $topic,
        string $subject,
        array $subjects,
        ?Collection $nodeGroup,
    ): string {
        $nodeTitle = $nodeGroup?->first()?->title;
        if (is_string($nodeTitle) && $nodeTitle !== '') {
            return $nodeTitle;
        }

        if ($topic !== null) {
            $configTitle = $subjects[$subject]['topics'][$topic]['title'] ?? null;
            if (is_string($configTitle) && $configTitle !== '') {
                return $configTitle;
            }
        } else {
            $subjectTitle = $subjects[$subject]['title'] ?? null;
            if (is_string($subjectTitle) && $subjectTitle !== '') {
                return $subjectTitle;
            }
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

        return implode(' → ', $labels);
    }
}
