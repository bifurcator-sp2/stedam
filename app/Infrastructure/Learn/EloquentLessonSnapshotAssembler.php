<?php

declare(strict_types=1);

namespace App\Infrastructure\Learn;

use App\Application\Learn\Exceptions\LessonNotFound;
use App\Application\Learn\Ports\LessonSnapshotAssemblerInterface;
use App\Application\Learn\Ports\ProcedureResolverPathInterface;
use App\Domain\Learn\ValueObjects\LessonId;
use App\Models\Lesson;
use App\Models\LessonBlock;

/**
 * Сборка сериализуемого снимка СТРУКТУРЫ урока для хэша снапшота.
 *
 * Ключевое свойство: текст генеративных (proc/test) заданий в снимок
 * НЕ попадает. Proc-узлы сериализуются с пустым default — так хэш
 * одинаков для всех учеников, стартовавших неизменённый урок, и не зависит
 * от случайности генератора (см. спеку §8.3, §14.17).
 *
 * Статические блоки входят в снимок как есть: их содержимое неизменно и
 * одинаково для всех прохождений одного снапшота.
 */
final readonly class EloquentLessonSnapshotAssembler implements LessonSnapshotAssemblerInterface
{
    public function __construct(
        private ProcedureResolverPathInterface $resolverPaths,
    ) {}

    public function assemble(LessonId $lessonId): array
    {
        $lesson = Lesson::query()
            ->with(['blocks.block', 'lessonBlockAsTests.block', 'taxonomies:id'])
            ->find($lessonId->value);

        if ($lesson === null) {
            throw new LessonNotFound("Lesson {$lessonId->value} not found");
        }

        $resolverPath = $this->resolverPaths->resolveForLesson($lessonId);

        $blocks = [];
        foreach ($lesson->allBlocksOrdered() as $order => $block) {
            /** @var LessonBlock $block */
            $isProc   = (bool) $block->is_proc;
            $settings = $block->settings ?? [];

            if ($isProc) {
                $settings = $this->blankProcNodes($settings);
            }

            $blocks[] = [
                'id'            => $block->id,
                'code'          => $block->block?->code,
                'order'         => $order,
                'is_proc'       => $isProc,
                'resolver_path' => $isProc ? $resolverPath : null,
                'complexity'    => (float) ($block->complexity ?? 0.0),
                'settings'      => $settings,
            ];
        }

        return [
            'lesson_id' => $lesson->id,
            'title'     => $lesson->title,
            'locale'    => $lesson->locale,
            'blocks'    => $blocks,
        ];
    }

    /**
     * Рекурсивно обнуляет default в proc-узлах (тех, у которых среди детей
     * есть ключ 'testLabel'). Структура узла сохраняется, меняется только
     * содержимое генеративного текста.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function blankProcNodes(array $node): array
    {
        // Список узлов (массив без 'key').
        if (!isset($node['key'])) {
            foreach ($node as $k => $child) {
                if (is_array($child)) {
                    $node[$k] = $this->blankProcNodes($child);
                }
            }

            return $node;
        }

        $isProc = false;
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child) && ($child['key'] ?? '') === 'testLabel') {
                $isProc = true;
                break;
            }
        }

        if ($isProc) {
            $node['default'] = '';
        }

        foreach ($node['children'] ?? [] as $k => $child) {
            if (is_array($child)) {
                $node['children'][$k] = $this->blankProcNodes($child);
            }
        }

        return $node;
    }
}
